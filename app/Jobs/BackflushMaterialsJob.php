<?php

declare(strict_types=1);

namespace Modules\MES\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\MESTables;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Backflushes the components tied to a completed operation.
 *
 * A snapshot BOM line is consumed by this operation when its
 * routing_operation_id matches the operation's, or — when the line has no
 * routing operation — when this is the last operation of the order (decision
 * D5). The job is idempotent per (operation, item): a unique constraint and a
 * pre-check prevent double consumption on retry.
 */
final class BackflushMaterialsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $production_order_operation_id)
    {
        $this->onConnection(config('mes.queue.connection'));
        $this->onQueue(config('mes.queue.name'));
    }

    public function handle(StockMovementRecorder $recorder, StockReader $reader): void
    {
        $operation = ProductionOrderOperation::query()->find($this->production_order_operation_id);
        $order = $operation?->productionOrder;

        if ($operation === null || $order === null) {
            return;
        }

        $basis = (float) ($order->quantity_produced ?? $order->quantity_planned);
        $last_sequence = (int) ProductionOrderOperation::query()
            ->where('production_order_id', $order->id)
            ->max('sequence');

        foreach ($order->bom_snapshot['lines'] ?? [] as $line) {
            if (($line['consumption_method'] ?? null) !== ConsumptionMethod::Backflush->value) {
                continue;
            }

            if (! $this->lineBelongsToOperation($line, $operation, $last_sequence)) {
                continue;
            }

            if ($this->alreadyConsumed($operation->id, (int) $line['item_id'])) {
                continue;
            }

            $this->consume($recorder, $reader, $order, $operation, $line, $basis);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineBelongsToOperation(array $line, ProductionOrderOperation $operation, int $last_sequence): bool
    {
        $line_routing_operation_id = $line['routing_operation_id'] ?? null;

        if ($line_routing_operation_id !== null) {
            return $operation->routing_operation_id === (int) $line_routing_operation_id;
        }

        return $operation->sequence === $last_sequence;
    }

    private function alreadyConsumed(int $operation_id, int $item_id): bool
    {
        return MaterialConsumption::query()
            ->where('production_order_operation_id', $operation_id)
            ->where('item_id', $item_id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function consume(
        StockMovementRecorder $recorder,
        StockReader $reader,
        \Modules\MES\Models\ProductionOrder $order,
        ProductionOrderOperation $operation,
        array $line,
        float $basis,
    ): void {
        $planned = (float) $line['quantity'] * $basis;
        $item_id = (int) $line['item_id'];
        $available = $reader->availableQuantity($item_id, (int) $order->warehouse_id, (int) $order->company_id);
        $consumed = max(0.0, min($planned, $available));
        $short = $consumed < $planned;

        MaterialConsumption::query()->create([
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation->id,
            'item_id' => $item_id,
            'warehouse_id' => $order->warehouse_id,
            'quantity_planned' => $planned,
            'quantity_consumed' => $consumed,
            'variance' => $consumed - $planned,
            'uom' => $line['uom'] ?? $order->uom,
            'is_backflush' => true,
            'stock_shortage' => $short,
            'recorded_at' => now(),
        ]);

        $this->recordConsumedStock($recorder, $order, $item_id, $consumed);

        if ($short) {
            event(new MaterialShortageDetected(
                company_id: (int) $order->company_id,
                item_id: $item_id,
                warehouse_id: (int) $order->warehouse_id,
                production_order_id: (int) $order->id,
                production_order_operation_id: (int) $operation->id,
                required_quantity: $planned,
                available_quantity: $available,
                is_backflush: true,
            ));
        }
    }

    /**
     * Post the stock-out for the actually consumed quantity, skipping the
     * movement when nothing is available (a full shortage).
     */
    private function recordConsumedStock(
        StockMovementRecorder $recorder,
        \Modules\MES\Models\ProductionOrder $order,
        int $item_id,
        float $consumed,
    ): void {
        $quantity = (int) round($consumed);

        if ($quantity <= 0) {
            return;
        }

        $recorder->record(new StockMovementData(
            item_id: $item_id,
            warehouse_id: $order->warehouse_id,
            company_id: $order->company_id,
            direction: 'out',
            quantity: $quantity,
            source_type: MESTables::ProductionOrders->value,
            source_id: $order->id,
            occurred_at: now(),
        ));
    }
}
