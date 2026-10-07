<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineState;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineStateInterval;

/**
 * The state history of a device. Recording a state at a moment is idempotent: the same state at the same
 * moment, or inside an interval already in that state, changes nothing, and the first state written for an
 * instant wins. A state older than the open interval splits the interval that holds its moment; two
 * neighbours left in the same state are merged.
 */
final class MachineStateIntervals
{
    public function observe(MachineDevice $device, MachineState $state, CarbonInterface $at): MachineStateChange
    {
        return $device->getConnection()->transaction(function () use ($device, $state, $at): MachineStateChange {
            $container = $this->containing($device, $at);

            if (! $container instanceof MachineStateInterval) {
                $next = MachineStateInterval::query()
                    ->where('device_id', $device->id)
                    ->where('started_at', '>', MachineTime::db($at))
                    ->orderBy('started_at')
                    ->first();

                $created = $this->create($device, $state, $at, $next?->started_at);

                return $this->mergeWithNext(new MachineStateChange([$created]), $created);
            }

            if ($container->state === $state || MachineTime::db($container->started_at) === MachineTime::db($at)) {
                return new MachineStateChange();
            }

            $old_end = $container->ended_at;
            $container->update(['ended_at' => MachineTime::local($at)]);
            $created = $this->create($device, $state, $at, $old_end);

            return $this->mergeWithNext(new MachineStateChange([$container, $created]), $created);
        });
    }

    /**
     * Records the alarm code on the interval that holds the moment, when it has none (the first alarm of a stop wins).
     */
    public function tagAlarm(MachineDevice $device, string $alarm_code, CarbonInterface $at): ?MachineStateInterval
    {
        $interval = $this->containing($device, $at);

        if (! $interval instanceof MachineStateInterval || $interval->alarm_code !== null) {
            return null;
        }

        $interval->update(['alarm_code' => $alarm_code]);

        return $interval;
    }

    private function containing(MachineDevice $device, CarbonInterface $at): ?MachineStateInterval
    {
        $moment = MachineTime::db($at);

        return MachineStateInterval::query()
            ->where('device_id', $device->id)
            ->where('started_at', '<=', $moment)
            ->where(static fn (Builder $query): Builder => $query->whereNull('ended_at')->orWhere('ended_at', '>', $moment))
            ->orderByDesc('started_at')
            ->first();
    }

    private function create(MachineDevice $device, MachineState $state, CarbonInterface $at, ?CarbonInterface $ended_at): MachineStateInterval
    {
        return MachineStateInterval::query()->create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'work_center_id' => $device->work_center_id,
            'state' => $state->value,
            'started_at' => MachineTime::local($at),
            'ended_at' => $ended_at instanceof CarbonInterface ? MachineTime::local($ended_at) : null,
        ]);
    }

    /**
     * When the interval just written ends where an interval in the same state starts, the two become one.
     */
    private function mergeWithNext(MachineStateChange $change, MachineStateInterval $written): MachineStateChange
    {
        if (! $written->ended_at instanceof CarbonInterface) {
            return $change;
        }

        $next = MachineStateInterval::query()
            ->where('device_id', $written->device_id)
            ->where('started_at', MachineTime::db($written->ended_at))
            ->first();

        if (! $next instanceof MachineStateInterval || $next->state !== $written->state) {
            return $change;
        }

        $removed = MachineTime::db($next->started_at);
        $next_end = $next->ended_at;
        $next->delete();
        $written->update(['ended_at' => $next_end]);

        return new MachineStateChange($change->changed, [...$change->removed_starts, $removed]);
    }
}
