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
 * StockLevel aggregate minus the live reservations that hold the item back.
 * MES depends on ERP (declared dependency), so importing ERP models here is
 * intentional; the ERP has no knowledge of MES.
 *
 * Two kinds of hold are subtracted: those pinned to this warehouse, and the
 * company-wide ones with a null warehouse (sales holds). The null-warehouse
 * subtraction is conservative — not attributable to a single warehouse, it is
 * taken off every warehouse's availability, so it may under-report when stock
 * is spread across warehouses, but MES backflush can never consume stock
 * another module reserved company-wide.
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

        $available = Decimal::sub(
            $available,
            $this->reservedCompanyWide($item_id, $company_id),
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

    /**
     * Sum of the live (soft or hard) company-wide reservations for the item:
     * those with a null warehouse, such as sales holds. Subtracted from every
     * warehouse's availability so MES never consumes stock reserved company-wide.
     * Only the tenant scope is lifted (the company is passed explicitly); the
     * soft-delete scope stays so closed rows never count.
     */
    private function reservedCompanyWide(int $item_id, int $company_id): string
    {
        $total = '0.0000';

        $quantities = StockReservation::query()
            ->withoutGlobalScope(BelongsToCompanyScope::class)
            ->where('company_id', $company_id)
            ->where('item_id', $item_id)
            ->whereNull('warehouse_id')
            ->active()
            ->pluck('quantity');

        foreach ($quantities as $quantity) {
            $total = Decimal::add($total, Decimal::format((string) $quantity));
        }

        return $total;
    }
}
