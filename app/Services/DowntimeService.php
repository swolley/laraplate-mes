<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;

/**
 * Opens and closes work-center downtimes, computing their duration on close and
 * exposing whether a work center is currently down.
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
}
