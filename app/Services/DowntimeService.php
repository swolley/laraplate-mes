<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;

/**
 * Opens and closes work-center downtimes, computing their duration on close and
 * exposing whether a work center is currently down and how long it was down in a window.
 */
final class DowntimeService
{
    public function open(WorkCenter $work_center, DowntimeCause $cause, ?int $operation_id = null, ?string $notes = null): Downtime
    {
        return Downtime::query()->create([
            'company_id' => $work_center->company_id,
            'work_center_id' => $work_center->id,
            'production_order_operation_id' => $operation_id,
            'cause' => $cause->value,
            'started_at' => now(),
            'ended_at' => null,
            'notes' => $notes,
        ]);
    }

    /**
     * Close an open downtime and persist its duration in minutes.
     *
     * @throws DomainException when the downtime is already closed.
     */
    public function close(Downtime $downtime): Downtime
    {
        throw_unless(
            $downtime->ended_at === null,
            new DomainException("Downtime {$downtime->id} is already closed."),
        );

        $ended_at = now();

        $downtime->update([
            'ended_at' => $ended_at,
            'duration_minutes' => (float) $downtime->started_at->diffInMinutes($ended_at),
        ]);

        return $downtime->refresh();
    }

    /**
     * Whether the work center currently has an open downtime.
     */
    public function isWorkCenterDown(int $work_center_id): bool
    {
        return Downtime::query()
            ->where('work_center_id', $work_center_id)
            ->whereNull('ended_at')
            ->exists();
    }

    /**
     * Minutes of unplanned downtime on a work center that overlap a window.
     *
     * Each downtime is clipped to the window; an open one runs until now. Planned
     * maintenance is left out, matching {@see OeeCalculatorService::availability()}.
     */
    public function unplannedMinutesWithin(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $window_start = Carbon::parse($from);
        $window_end = Carbon::parse($to);

        return Downtime::query()
            ->where('work_center_id', $work_center_id)
            ->where('cause', '!=', DowntimeCause::PlannedMaintenance->value)
            ->where('started_at', '<', $window_end)
            ->where(static fn (Builder $query): Builder => $query->whereNull('ended_at')->orWhere('ended_at', '>', $window_start))
            ->get()
            ->sum(static function (Downtime $downtime) use ($window_start, $window_end): float {
                $start = $downtime->started_at->max($window_start);
                $end = ($downtime->ended_at ?? now())->min($window_end);

                return $end->greaterThan($start) ? (float) $start->diffInMinutes($end) : 0.0;
            });
    }
}
