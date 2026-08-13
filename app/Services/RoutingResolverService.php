<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Exceptions\RoutingLockedException;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;

/**
 * Resolves the routing version effective on a given date and guards against
 * edits to routings already frozen into a released production order.
 */
final class RoutingResolverService
{
    /**
     * Return the active routing for an item on a given date, or null when none
     * is effective. The most recently effective version wins on overlap.
     */
    public function getActiveRouting(int $item_id, CarbonInterface $on_date): ?Routing
    {
        return Routing::query()
            ->where('item_id', $item_id)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $on_date)
            ->where(function (Builder $query) use ($on_date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on_date);
            })
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @throws RoutingLockedException when a released production order froze this routing.
     */
    public function assertNotLocked(Routing $routing): void
    {
        $locked = ProductionOrder::query()
            ->where('status', '!=', 'draft')
            ->where('routing_snapshot->id', $routing->id)
            ->exists();

        if ($locked) {
            throw new RoutingLockedException("Routing {$routing->id} is locked by a released production order.");
        }
    }
}
