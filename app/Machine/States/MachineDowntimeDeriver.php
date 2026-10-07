<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\DowntimeService;

/**
 * Turns the state history into downtimes. A stop is a downtime when its state is one of the work
 * center's downtime states and it lasted longer than the micro-stop threshold; an open stop is measured
 * to now. A downtime follows its interval in place (times only: the operator's cause and notes stay) and
 * goes away when its interval no longer qualifies.
 */
final class MachineDowntimeDeriver
{
    public function __construct(
        private readonly DowntimeService $downtimes,
        private readonly DowntimeCauseResolver $causes,
    ) {}

    /**
     * @return Downtime|null the downtime of the interval after the sync, null when it has none
     */
    public function sync(MachineStateInterval $interval): ?Downtime
    {
        // The caller may hold a model another writer has since changed or merged away.
        $current = MachineStateInterval::query()->find($interval->id);

        if (! $current instanceof MachineStateInterval) {
            return null;
        }

        $interval = $current;
        $work_center = WorkCenter::query()->withoutGlobalScopes()->find($interval->work_center_id);
        $device = MachineDevice::query()->withoutGlobalScopes()->find($interval->device_id);

        if (! $work_center instanceof WorkCenter || ! $device instanceof MachineDevice) {
            return null;
        }

        $existing = $this->downtimeStartingAt($interval->work_center_id, MachineTime::db($interval->started_at));
        $end = $interval->ended_at ?? now();
        $qualifies = $work_center->isDowntimeState($interval->state)
            && $interval->started_at->diffInMilliseconds($end, true) > $work_center->micro_stop_threshold_seconds * 1000;

        if (! $qualifies) {
            $existing?->delete();

            return null;
        }

        $downtime = $existing ?? $this->downtimes->openFromMachine(
            $device,
            $this->causes->resolve($device, $interval->state, $interval->alarm_code),
            $interval->started_at,
            $interval->alarm_code,
        );

        if ($existing instanceof Downtime && $existing->alarm_code === null && $interval->alarm_code !== null) {
            // An alarm that came after the downtime was opened: the code is recorded, and an unclassified cause is improved.
            $cause = $existing->cause === DowntimeCause::Unclassified ? $this->causes->resolve($device, $interval->state, $interval->alarm_code) : $existing->cause;
            $existing->update(['alarm_code' => $interval->alarm_code, 'cause' => $cause->value]);
        }

        return $interval->ended_at === null ? $downtime : $this->downtimes->closeFromMachine($downtime, $interval->ended_at);
    }

    /**
     * The downtime of an interval that was merged away moves to the interval that absorbed it when that one
     * has none (the operator's cause and notes survive); otherwise it is removed.
     */
    public function forget(int $work_center_id, string $started_at, ?string $absorbed_by = null): void
    {
        $downtime = $this->downtimeStartingAt($work_center_id, $started_at);

        if (! $downtime instanceof Downtime) {
            return;
        }

        if ($absorbed_by !== null && ! $this->downtimeStartingAt($work_center_id, $absorbed_by) instanceof Downtime) {
            Downtime::writingAsMachine(static fn (): bool => $downtime->update(['started_at' => $absorbed_by]));

            return;
        }

        $downtime->delete();
    }

    private function downtimeStartingAt(int $work_center_id, string $started_at): ?Downtime
    {
        return Downtime::query()
            ->where('work_center_id', $work_center_id)
            ->where('source', DowntimeSource::Machine->value)
            ->where('started_at', $started_at)
            ->first();
    }
}
