<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\ERP\Models\StockLevel;
use Modules\ERP\Models\StockReservation;
use Modules\ERP\Scopes\BelongsToCompanyScope;
use Modules\ERP\Support\Decimal;
use Modules\MES\Contracts\StockReader;

/**
 * Adapter reading available quantity from the ERP inventory: on-hand from the
 * StockLevel aggregate minus the live reservations pinned to the same warehouse.
 * MES depends on ERP (declared dependency), so importing ERP models here is
 * intentional; the ERP has no knowledge of MES.
 *
 * Company-wide reservations (null warehouse) are a documented v1 limitation:
 * they are not attributable to a single warehouse and are not subtracted here.
 */
final readonly class ErpStockReader implements StockReader
{
    public function availableQuantity(int $item_id, int $warehouse_id, int $company_id): float
    {
        $on_hand = StockLevel::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company_id)
            ->where('item_id', $item_id)
            ->where('warehouse_id', $warehouse_id)
            ->value('quantity');

        $available = Decimal::sub(
            Decimal::format((string) ($on_hand ?? '0')),
            $this->reservedForWarehouse($item_id, $warehouse_id, $company_id),
        );

        return (float) $available;
    }

    /**
     * Sum of the live (soft or hard) reservations pinned to this warehouse for
     * the item. Only the tenant scope is lifted (the company is passed
     * explicitly); the soft-delete scope stays so closed rows never count.
     */
    private function reservedForWarehouse(int $item_id, int $warehouse_id, int $company_id): string
    {
        $total = '0.0000';

        $quantities = StockReservation::query()
            ->withoutGlobalScope(BelongsToCompanyScope::class)
            ->where('company_id', $company_id)
            ->where('item_id', $item_id)
            ->where('warehouse_id', $warehouse_id)
            ->active()
            ->pluck('quantity');

        foreach ($quantities as $quantity) {
            $total = Decimal::add($total, Decimal::format((string) $quantity));
        }

        return $total;
    }
}
