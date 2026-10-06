<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\ERP\Casts\StockMovementDirection;
use Modules\ERP\Models\StockMovement;
use Modules\MES\Contracts\ProductionCostReader;
use Modules\MES\Models\ProductionOrder;

/**
 * Adapter summing the ERP stock-out movements sourced to a production order. The
 * movements carry the cost the ERP valued them at (FIFO layer or weighted average).
 */
final readonly class ErpProductionCostReader implements ProductionCostReader
{
    public function materialCost(int $company_id, int $production_order_id): float
    {
        $order = ProductionOrder::query()->withoutGlobalScopes()->find($production_order_id);

        if (! $order instanceof ProductionOrder || (int) $order->company_id !== $company_id) {
            return 0.0;
        }

        return (float) StockMovement::query()
            ->withoutGlobalScopes()
            ->whereMorphedTo('source', $order)
            ->get()
            ->filter(static fn (StockMovement $movement): bool => $movement->direction === StockMovementDirection::Out)
            ->sum(static fn (StockMovement $movement): float => (float) $movement->quantity * (float) $movement->unit_cost);
    }
}
