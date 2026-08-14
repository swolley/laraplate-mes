<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\ERP\Models\StockLevel;
use Modules\MES\Contracts\StockReader;

/**
 * Adapter reading on-hand quantity from the ERP StockLevel aggregate. MES
 * depends on ERP (declared dependency), so importing ERP models here is
 * intentional; the ERP has no knowledge of MES.
 */
final readonly class ErpStockReader implements StockReader
{
    public function availableQuantity(int $item_id, int $warehouse_id, int $company_id): float
    {
        $quantity = StockLevel::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company_id)
            ->where('item_id', $item_id)
            ->where('warehouse_id', $warehouse_id)
            ->value('quantity');

        return $quantity === null ? 0.0 : (float) $quantity;
    }
}
