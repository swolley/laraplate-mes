<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Models\QualityPlan;

/**
 * Resolves the quality plan effective for an item on a given date, optionally
 * scoped to a routing operation (in-process) or to the finished item when the
 * operation is null (final inspection). The most recently effective active plan
 * wins on overlap.
 */
final class QualityPlanResolver
{
    public function resolve(int $item_id, ?int $routing_operation_id, CarbonInterface $on_date): ?QualityPlan
    {
        return QualityPlan::query()
            ->where('item_id', $item_id)
            ->where('is_active', true)
            ->when(
                $routing_operation_id === null,
                static fn (Builder $query): Builder => $query->whereNull('routing_operation_id'),
                static fn (Builder $query): Builder => $query->where('routing_operation_id', $routing_operation_id),
            )
            ->whereDate('valid_from', '<=', $on_date)
            ->where(function (Builder $query) use ($on_date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on_date);
            })
            ->orderByDesc('valid_from')
            ->first();
    }
}
