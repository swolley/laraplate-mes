<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

use Carbon\CarbonImmutable;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\SparkplugAlias;

/**
 * The alias-to-name maps that Sparkplug B births declare and later data messages rely on. Nothing is
 * kept in memory between calls: another worker may have processed the birth.
 */
final class SparkplugAliasStore
{
    /**
     * Replaces the alias map of a device with the one its birth just declared, unless a newer birth
     * has declared one already (an old birth reprocessed, or processed late, must not roll the map back).
     *
     * @param  array<int, string>  $aliases  alias => metric name
     */
    public function remember(MachineSource $source, string $device_external_id, array $aliases, CarbonImmutable $declared_at): void
    {
        $source->getConnection()->transaction(function () use ($source, $device_external_id, $aliases, $declared_at): void {
            $query = SparkplugAlias::query()->withoutGlobalScopes()->where('source_id', $source->id)->where('device_external_id', $device_external_id);
            $latest = (clone $query)->max('declared_at');

            if (is_string($latest) && CarbonImmutable::parse($latest, config()->string('app.timezone')) > $declared_at) {
                return;
            }

            $aliases === [] ? $query->delete() : $query->whereNotIn('alias', array_keys($aliases))->delete();

            if ($aliases === []) {
                return;
            }

            SparkplugAlias::query()->withoutGlobalScopes()->upsert(
                array_map(static fn (int $alias, string $name): array => [
                    'company_id' => $source->company_id,
                    'source_id' => $source->id,
                    'device_external_id' => $device_external_id,
                    'alias' => $alias,
                    'name' => $name,
                    'declared_at' => $declared_at->setTimezone(config()->string('app.timezone')),
                ], array_keys($aliases), array_values($aliases)),
                ['source_id', 'device_external_id', 'alias'],
                ['name', 'declared_at'],
            );
        });
    }

    /**
     * @return array<int, string> alias => metric name
     */
    public function forDevice(MachineSource $source, string $device_external_id): array
    {
        $names = [];

        foreach (SparkplugAlias::query()->withoutGlobalScopes()->where('source_id', $source->id)->where('device_external_id', $device_external_id)->get() as $row) {
            $names[$row->alias] = $row->name;
        }

        return $names;
    }
}
