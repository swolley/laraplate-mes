<?php

declare(strict_types=1);

namespace Modules\MES\Contracts;

/**
 * Read side of the MES ↔ ERP stock boundary for costing: what the stock leaving
 * for a production order was worth, as posted by the ERP.
 */
interface ProductionCostReader
{
    /**
     * Total cost of the stock-outs posted against a production order
     * (quantity x the unit cost the ERP valued each movement at).
     */
    public function materialCost(int $company_id, int $production_order_id): float;
}
