<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\MES\Contracts\ProductionCostReader;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\MESTables;
use Modules\MES\Models\ProductionOrder;

/**
 * Receives the finished goods of a completed production order into the order's
 * warehouse, valued at the material cost the order consumed per unit produced.
 * Labour and machine time carry no cost rate yet, so they add nothing to it.
 */
final class FinishedGoodsReceiptService
{
    public function __construct(
        private StockMovementRecorder $recorder,
        private ProductionCostReader $costReader,
    ) {}

    /**
     * Post the stock-in. Nothing is posted for a quantity of zero or less.
     */
    public function receive(ProductionOrder $order, float $quantity_produced): void
    {
        if ($quantity_produced <= 0.0) {
            return;
        }

        $material_cost = $this->costReader->materialCost((int) $order->company_id, $order->id);

        $this->recorder->record(new StockMovementData(
            item_id: $order->item_id,
            warehouse_id: $order->warehouse_id,
            company_id: $order->company_id,
            direction: 'in',
            quantity: $quantity_produced,
            source_type: MESTables::ProductionOrders->value,
            source_id: $order->id,
            occurred_at: now(),
            unit_cost: $material_cost / $quantity_produced,
        ));
    }
}
