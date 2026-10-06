<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\ProductionOrderReleased;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\ComponentReservationService;

/**
 * Holds the BOM components of a production order in ERP stock the moment the
 * order is released. Runs synchronously so availability reflects the hold at
 * once; the hold is best-effort and never blocks the release.
 */
final readonly class ReserveComponentsForProductionOrder
{
    public function __construct(private ComponentReservationService $reservations) {}

    public function handle(ProductionOrderReleased $event): void
    {
        $order = ProductionOrder::query()->withoutGlobalScopes()->find($event->production_order_id);

        if ($order === null) {
            return;
        }

        $this->reservations->reserveForOrder($order);
    }
}
