<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;

/**
 * Remembers the devices and signals a source sent that nobody configured. A pair counts once per
 * message, and only on the first processing of that message, so reprocessing never inflates it;
 * `last_value` and `last_seen_at` only move forward in sample time.
 */
final class UnmappedSignalRecorder
{
    public function record(MachineSource $source, string $device_external_id, string $signal_key, ?string $value, CarbonImmutable $ts, bool $count): void
    {
        $this->recordMany($source, [['device' => $device_external_id, 'key' => $signal_key, 'value' => $value, 'ts' => $ts]], $count);
    }

    /**
     * @param  list<array{device: string, key: string, value: ?string, ts: CarbonImmutable}>  $entries
     */
    public function recordMany(MachineSource $source, array $entries, bool $count): void
    {
        $latest = [];

        foreach ($entries as $entry) {
            $pair = $entry['device'] . "\0" . $entry['key'];

            if (! isset($latest[$pair]) || $entry['ts'] >= $latest[$pair]['ts']) {
                $latest[$pair] = $entry;
            }
        }

        if ($latest === []) {
            return;
        }

        $existing = UnmappedSignal::query()
            ->withoutGlobalScopes()
            ->where('source_id', $source->id)
            ->whereIn('device_external_id', array_values(array_unique(array_column($latest, 'device'))))
            ->whereIn('signal_key', array_values(array_unique(array_column($latest, 'key'))))
            ->get()
            ->keyBy(static fn (UnmappedSignal $row): string => $row->device_external_id . "\0" . $row->signal_key);

        $rows = [];

        foreach ($latest as $pair => $entry) {
            $current = $existing->get($pair);
            $value = $entry['value'];
            $seen_at = $entry['ts'];
            $seen_count = $count ? 1 : 0;

            if ($current instanceof UnmappedSignal) {
                $seen_count += $current->seen_count;

                if ($entry['ts'] < $current->last_seen_at) {
                    $value = $current->last_value;
                    $seen_at = $current->last_seen_at;
                }
            }

            $rows[] = [
                'company_id' => $source->company_id,
                'source_id' => $source->id,
                'device_external_id' => $entry['device'],
                'signal_key' => $entry['key'],
                'last_value' => $value,
                'last_seen_at' => $seen_at->setTimezone(config()->string('app.timezone')),
                'seen_count' => $seen_count,
            ];
        }

        UnmappedSignal::query()->withoutGlobalScopes()->upsert(
            $rows,
            ['source_id', 'device_external_id', 'signal_key'],
            ['last_value', 'last_seen_at', 'seen_count'],
        );
    }
}
