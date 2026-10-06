<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\ProductionOrderCompleted;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\ComponentReservationService;

/**
 * Frees whatever component holds a completed production order still carries.
 * A completed order can no longer be cancelled, so without this its backflush
 * shortfall and its manual-consumption lines (reserved at release, consumed
 * outside the reservation) would stay `hard` forever and leak availability.
 */
final readonly class ReleaseComponentsAfterCompletion
{
    public function __construct(private ComponentReservationService $reservations) {}

    public function handle(ProductionOrderCompleted $event): void
    {
        $order = ProductionOrder::query()->withoutGlobalScopes()->find($event->production_order_id);

        if ($order === null) {
            return;
        }

        $this->reservations->releaseForOrder($order);
    }
}
