<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DomainException;
use Modules\ERP\Casts\DocumentType;
use Modules\ERP\Casts\TracingType;
use Modules\ERP\Models\Company;
use Modules\ERP\Services\Accounting\DocumentNumberAllocator;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\Bom;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;

/**
 * Orchestrates the production order lifecycle: allocation of the document
 * number, immutable freezing of the effective BOM and routing snapshots, and
 * the draft -> released -> completed / cancelled state transitions.
 */
final class ProductionOrderService
{
    public function __construct(
        private BomExplosionService $bomExplosionService,
        private RoutingResolverService $routingResolverService,
        private DocumentNumberAllocator $documentNumberAllocator,
        private ProductionOrderOperationService $operationService,
        private LotTracingService $lotTracingService,
        private QualityCheckPlanner $qualityCheckPlanner,
    ) {}

    /**
     * Create a draft production order, freezing the BOM and routing effective
     * at the planned start date into immutable JSON snapshots.
     *
     * @param  array{
     *     company_id: int,
     *     item_id: int,
     *     quantity_planned: float|int|string,
     *     uom: string,
     *     planned_start_at: DateTimeInterface|string,
     *     planned_end_at: DateTimeInterface|string,
     *     warehouse_id: int,
     *     sales_order_id?: int|null,
     *     sales_order_line_id?: int|null,
     * }  $payload
     */
    public function create(array $payload): ProductionOrder
    {
        $company = Company::query()->withoutGlobalScopes()->findOrFail($payload['company_id']);
        $on_date = CarbonImmutable::parse($payload['planned_start_at']);

        $number = $this->documentNumberAllocator->next(
            $company,
            DocumentType::ProductionOrder,
            (int) $on_date->format('Y'),
        );

        return ProductionOrder::query()->withoutGlobalScopes()->create([
            'company_id' => $payload['company_id'],
            'number' => $number,
            'item_id' => $payload['item_id'],
            'quantity_planned' => $payload['quantity_planned'],
            'uom' => $payload['uom'],
            'status' => ProductionOrderStatus::Draft->value,
            'planned_start_at' => $payload['planned_start_at'],
            'planned_end_at' => $payload['planned_end_at'],
            'warehouse_id' => $payload['warehouse_id'],
            'sales_order_id' => $payload['sales_order_id'] ?? null,
            'sales_order_line_id' => $payload['sales_order_line_id'] ?? null,
            'bom_snapshot' => $this->buildBomSnapshot((int) $payload['item_id'], $on_date),
            'routing_snapshot' => $this->buildRoutingSnapshot((int) $payload['item_id'], $on_date),
        ]);
    }

    /**
     * Release a draft order for execution, materialising its operations from
     * the frozen routing snapshot.
     *
     * @throws DomainException when the order is not in draft state.
     */
    public function release(ProductionOrder $order): ProductionOrder
    {
        throw_unless(
            $order->status->canRelease(),
            new DomainException("Production order {$order->id} cannot be released from status {$order->status->value}."),
        );

        return $order->getConnection()->transaction(function () use ($order): ProductionOrder {
            $order->update(['status' => ProductionOrderStatus::Released->value]);
            $this->operationService->generateForOrder($order);

            return $order->refresh();
        });
    }

    /**
     * Record produced quantity and complete the order.
     *
     * Lot/serial generation (Task 9) and finished-goods stock-in are layered on
     * top of this transition by their respective services.
     *
     * @throws DomainException when the order is not in an executable state.
     */
    public function complete(ProductionOrder $order, float $quantity_produced, ?string $lot_code = null): ProductionOrder
    {
        throw_unless(
            in_array($order->status, [ProductionOrderStatus::Released, ProductionOrderStatus::InProgress], true),
            new DomainException("Production order {$order->id} cannot be completed from status {$order->status->value}."),
        );

        return $order->getConnection()->transaction(function () use ($order, $quantity_produced, $lot_code): ProductionOrder {
            $order->update([
                'quantity_produced' => $quantity_produced,
                'status' => ProductionOrderStatus::Completed->value,
                'actual_end_at' => now(),
            ]);

            if ($this->requiresLot($order)) {
                $this->lotTracingService->createProductionLot($order, $quantity_produced, $lot_code);
            }

            $this->qualityCheckPlanner->forOrderCompletion($order);

            return $order->refresh();
        });
    }

    /**
     * Cancel a draft or released order.
     *
     * @throws DomainException when the order can no longer be cancelled.
     */
    public function cancel(ProductionOrder $order): ProductionOrder
    {
        throw_unless(
            $order->status->canCancel(),
            new DomainException("Production order {$order->id} cannot be cancelled from status {$order->status->value}."),
        );

        return $order->getConnection()->transaction(function () use ($order): ProductionOrder {
            $order->update(['status' => ProductionOrderStatus::Cancelled->value]);

            return $order->refresh();
        });
    }

    /**
     * Whether the produced item is lot- or serial-traced and therefore needs a
     * lot generated on completion.
     */
    private function requiresLot(ProductionOrder $order): bool
    {
        $tracing_type = $order->item?->tracing_type;

        return $tracing_type === TracingType::Lot || $tracing_type === TracingType::Serial;
    }

    /**
     * Freeze the item's active BOM direct lines into an immutable snapshot.
     * Quantities are per finished unit; consumers scale by produced quantity.
     *
     * @return array{id: int|null, version: string|null, lines: list<array<string, mixed>>}
     */
    private function buildBomSnapshot(int $item_id, CarbonInterface $on_date): array
    {
        $bom = $this->bomExplosionService->getActiveBom($item_id, $on_date);

        if (! $bom instanceof Bom) {
            return ['id' => null, 'version' => null, 'lines' => []];
        }

        $lines = $bom->bomLines->map(static fn ($line): array => [
            'bom_line_id' => $line->id,
            'item_id' => $line->item_id,
            'quantity' => (float) $line->quantity,
            'uom' => $line->uom,
            'consumption_method' => $line->consumption_method->value,
            'routing_operation_id' => $line->routing_operation_id,
        ])->all();

        return ['id' => $bom->id, 'version' => $bom->version, 'lines' => $lines];
    }

    /**
     * Freeze the item's active routing operations into an immutable snapshot.
     *
     * @return array{id: int|null, version: string|null, operations: list<array<string, mixed>>}
     */
    private function buildRoutingSnapshot(int $item_id, CarbonInterface $on_date): array
    {
        $routing = $this->routingResolverService->getActiveRouting($item_id, $on_date);

        if (! $routing instanceof Routing) {
            return ['id' => null, 'version' => null, 'operations' => []];
        }

        $operations = $routing->routingOperations->map(static fn ($operation): array => [
            'routing_operation_id' => $operation->id,
            'work_center_id' => $operation->work_center_id,
            'sequence' => $operation->sequence,
            'description' => $operation->description,
            'setup_time_minutes' => $operation->setup_time_minutes,
            'cycle_time_minutes' => (float) $operation->cycle_time_minutes,
            'is_parallel' => $operation->is_parallel,
        ])->all();

        return ['id' => $routing->id, 'version' => $routing->version, 'operations' => $operations];
    }
}
