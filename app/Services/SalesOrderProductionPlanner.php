<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonImmutable;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\SalesOrder;
use Modules\ERP\Models\SalesOrderLine;
use Modules\MES\Data\ProductionPlanningResult;
use Modules\MES\Enums\ProductionPlanningSkipReason;
use Modules\MES\Models\Bom;
use Modules\MES\Models\ProductionOrder;

/**
 * Creates production orders for the manufactured lines of a confirmed sales
 * order. A line yields an order only when it references a stock item that has
 * an active BOM; purchased/service lines and lines already planned are skipped.
 * The planned quantity is the line's outstanding amount (ordered minus already
 * delivered), so a fully delivered line produces nothing. Planning is
 * idempotent per sales order line, so a re-confirmed or replayed event never
 * produces duplicates.
 */
final class SalesOrderProductionPlanner
{
    public function __construct(
        private BomExplosionService $bomExplosionService,
        private ProductionWarehouseResolver $warehouseResolver,
        private ProductionLeadTimeEstimator $leadTimeEstimator,
        private ProductionOrderService $productionOrderService,
    ) {}

    public function planForOrder(SalesOrder $order): ProductionPlanningResult
    {
        $order->loadMissing('lines');
        $now = CarbonImmutable::now();

        $created = [];
        $skipped = [];

        foreach ($order->lines as $line) {
            $order_from_line = $this->planLine($order, $line, $now);

            if ($order_from_line instanceof ProductionOrder) {
                $created[] = $order_from_line;

                continue;
            }

            $skipped[(int) $line->id] = $order_from_line;
        }

        return new ProductionPlanningResult($created, $skipped);
    }

    private function planLine(SalesOrder $order, SalesOrderLine $line, CarbonImmutable $now): ProductionOrder|ProductionPlanningSkipReason
    {
        if ($line->item_id === null) {
            return ProductionPlanningSkipReason::NoItem;
        }

        $quantity = (float) $line->qty_ordered - (float) $line->qty_delivered;

        if ($quantity <= 0.0) {
            return ProductionPlanningSkipReason::NothingToProduce;
        }

        if ($this->alreadyPlanned((int) $line->id)) {
            return ProductionPlanningSkipReason::AlreadyPlanned;
        }

        if (! $this->bomExplosionService->getActiveBom((int) $line->item_id, $now) instanceof Bom) {
            return ProductionPlanningSkipReason::NotManufactured;
        }

        $warehouse_id = $this->warehouseResolver->resolve((int) $order->company_id);

        if ($warehouse_id === null) {
            return ProductionPlanningSkipReason::WarehouseUnresolved;
        }

        $window = $this->leadTimeEstimator->estimate((int) $line->item_id, $quantity, $now);

        return $this->productionOrderService->create([
            'company_id' => (int) $order->company_id,
            'item_id' => (int) $line->item_id,
            'quantity_planned' => $quantity,
            'uom' => $this->itemUom((int) $line->item_id),
            'planned_start_at' => $window['start'],
            'planned_end_at' => $window['end'],
            'warehouse_id' => $warehouse_id,
            'sales_order_id' => (int) $order->id,
            'sales_order_line_id' => (int) $line->id,
        ]);
    }

    private function alreadyPlanned(int $sales_order_line_id): bool
    {
        return ProductionOrder::query()
            ->withoutGlobalScopes()
            ->where('sales_order_line_id', $sales_order_line_id)
            ->exists();
    }

    private function itemUom(int $item_id): string
    {
        return (string) Item::query()
            ->withoutGlobalScopes()
            ->whereKey($item_id)
            ->value('uom');
    }
}
