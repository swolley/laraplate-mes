<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Machine\States\MachineDowntimeDeriver;
use Modules\MES\Machine\States\MachineStateChange;
use Modules\MES\Machine\States\MachineStateIntervals;
use Modules\MES\Models\MachineStateInterval;

/**
 * Keeps the state history and the downtimes derived from it. Runs inside the processing job, on the
 * samples of one message in time order; it writes only what is new, so running it again changes nothing.
 */
final class MachineStateRecorder
{
    public function __construct(
        private readonly MachineStateIntervals $intervals,
        private readonly MachineDowntimeDeriver $deriver,
    ) {}

    public function handle(MachineStateObserved $event): void
    {
        $change = new MachineStateChange();
        $tagged = [];

        foreach ($event->samples as $sample) {
            if ($sample->state !== null) {
                $change = $change->merge($this->intervals->observe($sample->device, $sample->state, $sample->sample->ts));
            }

            if ($sample->alarm_code !== null) {
                $interval = $this->intervals->tagAlarm($sample->device, $sample->alarm_code, $sample->sample->ts);

                if ($interval instanceof MachineStateInterval) {
                    $tagged[] = $interval;
                }
            }
        }

        foreach ($change->removed_starts as $started_at) {
            $this->deriver->forget($event->work_center_id, $started_at);
        }

        $ids = [];

        foreach ([...$change->changed, ...$tagged] as $interval) {
            $ids[$interval->id] = true;
        }

        foreach (array_keys($ids) as $id) {
            // Reloaded: a later sample of the same event may have changed or merged it since.
            $current = MachineStateInterval::query()->find($id);

            if ($current instanceof MachineStateInterval) {
                $this->deriver->sync($current);
            }
        }
    }
}
