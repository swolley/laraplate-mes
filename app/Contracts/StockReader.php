<?php

declare(strict_types=1);

namespace Modules\MES\Contracts;

/**
 * Read side of the MES ↔ ERP stock boundary. Complements
 * {@see StockMovementRecorder} (the write side) so MES can detect a shortage
 * before attempting a consumption the ERP would reject.
 */
interface StockReader
{
    /**
     * Currently available on-hand quantity for an item in a warehouse.
     */
    public function availableQuantity(int $item_id, int $warehouse_id, int $company_id): float;
}
