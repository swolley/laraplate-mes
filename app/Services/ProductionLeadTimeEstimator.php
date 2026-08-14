<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonImmutable;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;

/**
 * Derives the planned start/end window for a production order from the standard
 * minutes of the item's active routing. When the item has no routing to size
 * the work, a configurable default lead time (working days) is applied.
 */
final class ProductionLeadTimeEstimator
{
    public function __construct(private RoutingResolverService $routingResolver) {}

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function estimate(int $item_id, float $quantity, ?CarbonImmutable $from = null): array
    {
        $start = $from ?? CarbonImmutable::now();
        $minutes = $this->routingMinutes($item_id, $quantity, $start);

        $days = $minutes > 0.0
            ? (int) max(1, (int) ceil($minutes / $this->dailyMinutes()))
            : $this->defaultLeadTimeDays();

        return ['start' => $start, 'end' => $start->addWeekdays($days)];
    }

    private function routingMinutes(int $item_id, float $quantity, CarbonImmutable $on_date): float
    {
        $routing = $this->routingResolver->getActiveRouting($item_id, $on_date);

        if (! $routing instanceof Routing) {
            return 0.0;
        }

        return $routing->routingOperations->sum(
            static fn (RoutingOperation $operation): float => $operation->setup_time_minutes
                + ((float) $operation->cycle_time_minutes * $quantity),
        );
    }

    private function dailyMinutes(): float
    {
        return max(1.0, (float) config('mes.production.daily_minutes', 480));
    }

    private function defaultLeadTimeDays(): int
    {
        return max(1, (int) config('mes.production.default_lead_time_days', 5));
    }
}
