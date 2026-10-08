<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Machine\Process\ProcessSample;
use Modules\MES\Machine\Process\ProcessValueStore;

/**
 * Hands the process value samples of a message to the store, in one batch. The event also carries the order and
 * operation reference samples, which are not process values, and a value that is not a number is not a reading.
 * A sample older than the raw retention is not stored: the next prune would delete it and its minute could no
 * longer be rebuilt. Storing a sample twice changes nothing.
 */
final class ProcessValueRecorder
{
    public function __construct(
        private readonly ProcessValueStore $store,
    ) {}

    public function handle(ProcessValuesSampled $event): void
    {
        $oldest = now()->subDays(config()->integer('mes.machine.raw_retention_days'));
        $samples = [];
        $dropped = 0;

        foreach ($event->samples as $sample) {
            if ($sample->signal->role !== SignalRole::ProcessValue || ! is_numeric($sample->sample->value)) {
                continue;
            }

            if ($sample->sample->ts->lessThan($oldest)) {
                $dropped++;

                continue;
            }

            $samples[] = new ProcessSample($event->company_id, $sample->signal->id, $event->device_id, $event->work_center_id, $sample->production_order_operation_id, $sample->sample->ts, (float) $sample->sample->value, $sample->sample->quality);
        }

        if ($dropped > 0) {
            Log::info('Process value samples older than the raw retention were not stored.', ['device_id' => $event->device_id, 'dropped' => $dropped]);
        }

        if ($samples === []) {
            return;
        }

        $this->store->write($samples);

        $this->afterWrite(array_values(array_unique(array_filter(array_map(static fn (ProcessSample $sample): ?int => $sample->production_order_operation_id, $samples)))));
    }

    /**
     * Called with the operations the stored samples are attributed to.
     *
     * @param  list<int>  $operation_ids
     */
    protected function afterWrite(array $operation_ids): void {}
}
