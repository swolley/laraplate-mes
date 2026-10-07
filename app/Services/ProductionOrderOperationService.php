<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use LogicException;
use Illuminate\Support\Collection;
use Modules\MES\Data\WorkCenterKpis;
use Modules\MES\Enums\OperatorLogAction;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Events\CapacityOverloadDetected;
use Modules\MES\Events\OperationCompleted;
use Modules\MES\Events\OperationSkipped;
use Modules\MES\Events\OperationStarted;
use Modules\MES\Events\ProductionOrderStarted;
use Modules\MES\Jobs\BackflushMaterialsJob;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Drives execution of a production order's operations: generation from the
 * frozen routing snapshot and the planned -> in_progress -> completed / skipped
 * transitions, computing operator efficiency on completion.
 */
final class ProductionOrderOperationService
{
    private const float MAX_EFFICIENCY = 999.99;

    public function __construct(
        private ShiftVerificationService $shiftVerificationService,
        private QualityCheckPlanner $qualityCheckPlanner,
        private WorkCenterKpiStore $kpiStore,
    ) {}

    /**
     * Materialise operations for a released order from its routing snapshot.
     *
     * @return Collection<int, ProductionOrderOperation>
     */
    public function generateForOrder(ProductionOrder $order): Collection
    {
        $operations = $order->routing_snapshot['operations'] ?? [];

        return $order->getConnection()->transaction(static function () use ($order, $operations): Collection {
            $created = collect();

            foreach ($operations as $operation) {
                $created->push(ProductionOrderOperation::query()->create([
                    'production_order_id' => $order->id,
                    'routing_operation_id' => $operation['routing_operation_id'] ?? null,
                    'work_center_id' => $operation['work_center_id'],
                    'sequence' => $operation['sequence'],
                    'description' => $operation['description'] ?? '',
                    'status' => ProductionOrderOperationStatus::Planned->value,
                    'setup_time_minutes' => $operation['setup_time_minutes'] ?? 0,
                    'cycle_time_minutes' => $operation['cycle_time_minutes'] ?? 0,
                    'is_parallel' => $operation['is_parallel'] ?? false,
                ]));
            }

            return $created;
        });
    }

    /**
     * Begin an operation. The first operation started on a released order moves
     * the order itself to in progress.
     *
     * @throws DomainException when the operation is already started or finished.
     */
    public function start(ProductionOrderOperation $operation): ProductionOrderOperation
    {
        throw_unless(
            $operation->status->canStart(),
            new DomainException("Operation {$operation->id} cannot start from status {$operation->status->value}."),
        );

        $order = $this->orderOf($operation);
        $order_started = $operation->getConnection()->transaction(static function () use ($operation, $order): bool {
            $operation->update([
                'status' => ProductionOrderOperationStatus::InProgress->value,
                'actual_start_at' => now(),
            ]);

            if ($order->status !== ProductionOrderStatus::Released) {
                return false;
            }

            $order->status = ProductionOrderStatus::InProgress;
            $order->actual_start_at = now();
            $order->save();

            return true;
        });

        $this->shiftVerificationService->logOperatorAction($operation, OperatorLogAction::Started);

        if ($order_started) {
            ProductionOrderStarted::dispatch($order->company_id, $order->id);
        }

        OperationStarted::dispatch($order->company_id, $order->id, $operation->id, $operation->work_center_id);
        $this->warnWhenOverloaded($operation);

        return $operation->refresh();
    }

    /**
     * Complete an operation, recording actual minutes and efficiency.
     *
     * Efficiency is standard minutes over actual minutes as a percentage,
     * clamped to [0, 999.99]. Backflush and quality checks are dispatched here;
     * {@see OperationCompleted} is the hook for everything else.
     *
     * @throws DomainException when the operation is not in progress.
     */
    public function complete(ProductionOrderOperation $operation, ?float $actual_minutes = null): ProductionOrderOperation
    {
        throw_unless(
            $operation->status === ProductionOrderOperationStatus::InProgress,
            new DomainException("Operation {$operation->id} cannot be completed from status {$operation->status->value}."),
        );

        $ended_at = now();
        $actual = $actual_minutes ?? ($operation->actual_start_at?->diffInMinutes($ended_at) ?? 0.0);

        $operation->forceFill([
            'status' => ProductionOrderOperationStatus::Completed->value,
            'actual_end_at' => $ended_at,
            'actual_minutes' => $actual,
            'efficiency' => $this->efficiency($operation, (float) $actual),
            ...$this->declaredPrefill($operation),
        ])->save();

        $this->shiftVerificationService->logOperatorAction($operation, OperatorLogAction::Completed);
        BackflushMaterialsJob::dispatch($operation->id);
        $this->qualityCheckPlanner->forOperation($operation);

        OperationCompleted::dispatch($this->orderOf($operation)->company_id, $operation->production_order_id, $operation->id, $operation->work_center_id);

        return $operation->refresh();
    }

    /**
     * Skip an operation that is not required for this order.
     *
     * @throws DomainException when the operation has already completed.
     */
    public function skip(ProductionOrderOperation $operation): ProductionOrderOperation
    {
        throw_if(
            $operation->status === ProductionOrderOperationStatus::Completed,
            new DomainException("Operation {$operation->id} is already completed and cannot be skipped."),
        );

        $operation->update(['status' => ProductionOrderOperationStatus::Skipped->value]);

        OperationSkipped::dispatch($this->orderOf($operation)->company_id, $operation->production_order_id, $operation->id, $operation->work_center_id);

        return $operation->refresh();
    }

    /**
     * What the machine counted becomes the starting point of the declared quantities, which the operator may
     * correct afterwards (see {@see OperationQuantityDeclarer}). Quantities already declared are kept.
     *
     * @return array<string, float>
     */
    private function declaredPrefill(ProductionOrderOperation $operation): array
    {
        $good = (float) $operation->machine_good_quantity;
        $scrap = (float) $operation->machine_scrap_quantity;

        if (($good <= 0.0 && $scrap <= 0.0) || $operation->declared_good_quantity !== null || $operation->declared_scrap_quantity !== null) {
            return [];
        }

        return ['declared_good_quantity' => $good, 'declared_scrap_quantity' => $scrap];
    }

    private function orderOf(ProductionOrderOperation $operation): ProductionOrder
    {
        return $operation->productionOrder ?? throw new LogicException("Operation {$operation->id} has no production order.");
    }

    /**
     * Non-blocking capacity warning, read from the materialised KPIs of the day:
     * nothing is recomputed live, and a day nobody materialised yet warns about nothing.
     */
    private function warnWhenOverloaded(ProductionOrderOperation $operation): void
    {
        $kpis = $this->kpiStore->get($operation->work_center_id, now());

        if (! $kpis instanceof WorkCenterKpis || ! $kpis->overloaded) {
            return;
        }

        CapacityOverloadDetected::dispatch(
            $this->orderOf($operation)->company_id,
            $operation->work_center_id,
            $operation->production_order_id,
            $operation->id,
            $kpis->capacity_load,
            $kpis->available_minutes,
        );
    }

    /**
     * Standard-over-actual efficiency percentage, clamped to [0, 999.99].
     */
    private function efficiency(ProductionOrderOperation $operation, float $actual_minutes): float
    {
        if ($actual_minutes <= 0.0) {
            return 0.0;
        }

        $quantity = (float) ($operation->productionOrder->quantity_planned ?? 0.0);
        $standard = (float) $operation->setup_time_minutes + (float) $operation->cycle_time_minutes * $quantity;

        return min(self::MAX_EFFICIENCY, max(0.0, $standard / $actual_minutes * 100));
    }
}
