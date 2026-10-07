<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\PartsCounted;
use Modules\MES\Machine\Counts\CounterDelta;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Machine\Support\IdempotentWriter;
use Modules\MES\Models\MachineCount;
use Modules\MES\Services\OperationCountTally;

/**
 * Stores the pieces counted: one row per count sample with its delta (see {@see CounterDelta}). A sample is
 * written once (unique per signal and moment); a late sample is inserted by its time and the row after it
 * is recomputed against it, so processing a message again, or in another order, leaves the same rows.
 */
final class PartsCountRecorder
{
    public function __construct(
        private readonly IdempotentWriter $writer,
        private readonly OperationCountTally $tally,
    ) {}

    public function handle(PartsCounted $event): void
    {
        $operations = [];

        foreach ($event->samples as $sample) {
            if (! is_numeric($sample->sample->value)) {
                continue;
            }

            foreach ($this->record($event, $sample) as $operation_id) {
                $operations[$operation_id] = true;
            }
        }

        $this->afterWrite(array_keys($operations));
    }

    /**
     * @param  list<int>  $operation_ids  the operations whose rows changed
     */
    private function afterWrite(array $operation_ids): void
    {
        foreach ($operation_ids as $operation_id) {
            $this->tally->refresh($operation_id);
        }
    }

    /**
     * @return list<int> the operations whose rows changed
     */
    private function record(PartsCounted $event, ResolvedSample $sample): array
    {
        $signal = $sample->signal;
        $column = $this->columnOf($signal->role);

        if ($column === null) {
            return [];
        }

        $config = is_array($signal->config) ? $signal->config : [];
        $mode = ($config['mode'] ?? 'cumulative') === 'delta' ? 'delta' : 'cumulative';
        $rollover = isset($config['rollover_max']) && is_numeric($config['rollover_max']) ? (float) $config['rollover_max'] : null;
        $value = (float) $sample->sample->value;
        $moment = MachineTime::db($sample->sample->ts);

        return $sample->device->getConnection()->transaction(function () use ($event, $sample, $column, $mode, $rollover, $value, $moment): array {
            $previous = MachineCount::query()->where('signal_id', $sample->signal->id)->where('ts', '<', $moment)->orderByDesc('ts')->first();
            $delta = CounterDelta::between($previous instanceof MachineCount ? (float) $previous->raw_value : null, $value, $mode, $rollover);
            $now = now()->format('Y-m-d H:i:s');

            $stored = $this->writer->insert($sample->device->getConnection(), 'mes_machine_counts', [
                'company_id' => $event->company_id,
                'signal_id' => $sample->signal->id,
                'device_id' => $event->device_id,
                'work_center_id' => $event->work_center_id,
                'production_order_operation_id' => $sample->production_order_operation_id,
                'ts' => $moment,
                'good' => $column === 'good' ? $delta : 0,
                'scrap' => $column === 'scrap' ? $delta : 0,
                'total' => $column === 'total' ? $delta : 0,
                'raw_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $stored) {
                return [];
            }

            $changed = $sample->production_order_operation_id === null ? [] : [$sample->production_order_operation_id];
            $next = MachineCount::query()->where('signal_id', $sample->signal->id)->where('ts', '>', $moment)->orderBy('ts')->first();

            if ($next instanceof MachineCount && $mode === 'cumulative') {
                $next->forceFill([$column => CounterDelta::between($value, (float) $next->raw_value, $mode, $rollover)])->save();

                if ($next->production_order_operation_id !== null) {
                    $changed[] = $next->production_order_operation_id;
                }
            }

            return $changed;
        });
    }

    private function columnOf(SignalRole $role): ?string
    {
        return match ($role) {
            SignalRole::GoodCount => 'good',
            SignalRole::ScrapCount => 'scrap',
            SignalRole::TotalCount => 'total',
            default => null,
        };
    }
}
