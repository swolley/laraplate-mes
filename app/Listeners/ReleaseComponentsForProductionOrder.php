<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\ProductionOrderCancelled;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\ComponentReservationService;

/**
 * Frees the BOM component reservations held for a production order when the
 * order is cancelled, returning the quantity to availability.
 */
final readonly class ReleaseComponentsForProductionOrder
{
    public function __construct(private ComponentReservationService $reservations) {}

    public function handle(ProductionOrderCancelled $event): void
    {
        $order = ProductionOrder::query()->withoutGlobalScopes()->find($event->production_order_id);

        if ($order === null) {
            return;
        }

        $this->reservations->releaseForOrder($order);
    }
}
