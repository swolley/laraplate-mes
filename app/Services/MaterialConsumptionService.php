<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Illuminate\Support\Facades\DB;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\MESTables;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;

/**
 * Records operator-driven (manual) material consumption against a production
 * order, complementing the automatic {@see BackflushMaterialsJob}.
 */
final class MaterialConsumptionService
{
    public function __construct(
        private StockMovementRecorder $recorder,
        private StockReader $reader,
    ) {}

    /**
     * Record a manual consumption. When stock is short the consumption is
     * flagged, no stock-out is posted, and a {@see MaterialShortageDetected}
     * event is emitted; otherwise the corresponding stock-out is recorded.
     */
    public function recordManual(
        ProductionOrder $order,
        int $item_id,
        float $quantity,
        string $uom = 'pcs',
        ?int $operation_id = null,
    ): MaterialConsumption {
        return DB::transaction(function () use ($order, $item_id, $quantity, $uom, $operation_id): MaterialConsumption {
            $available = $this->reader->availableQuantity($item_id, (int) $order->warehouse_id, (int) $order->company_id);
            $short = $available < $quantity;

            $consumption = MaterialConsumption::query()->create([
                'production_order_id' => $order->id,
                'production_order_operation_id' => $operation_id,
                'item_id' => $item_id,
                'warehouse_id' => $order->warehouse_id,
                'quantity_planned' => $quantity,
                'quantity_consumed' => $quantity,
                'variance' => 0,
                'uom' => $uom,
                'is_backflush' => false,
                'stock_shortage' => $short,
                'recorded_at' => now(),
            ]);

            if ($short) {
                event(new MaterialShortageDetected(
                    company_id: (int) $order->company_id,
                    item_id: $item_id,
                    warehouse_id: (int) $order->warehouse_id,
                    production_order_id: (int) $order->id,
                    production_order_operation_id: $operation_id,
                    required_quantity: $quantity,
                    available_quantity: $available,
                    is_backflush: false,
                ));

                return $consumption;
            }

            $this->recorder->record(new StockMovementData(
                item_id: $item_id,
                warehouse_id: $order->warehouse_id,
                company_id: $order->company_id,
                direction: 'out',
                quantity: (int) round($quantity),
                source_type: MESTables::ProductionOrders->value,
                source_id: $order->id,
                occurred_at: now(),
            ));

            return $consumption;
        });
    }
}
