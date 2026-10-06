<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

use Modules\MES\Models\MachineSource;
use Modules\MES\Models\SparkplugAlias;

/**
 * The alias-to-name maps that Sparkplug B births declare and later data messages rely on. Nothing is
 * kept in memory between calls: another worker may have processed the birth.
 */
final class SparkplugAliasStore
{
    /**
     * Replaces the alias map of a device with the one its birth just declared.
     *
     * @param  array<int, string>  $aliases  alias => metric name
     */
    public function remember(MachineSource $source, string $device_external_id, array $aliases): void
    {
        $query = SparkplugAlias::query()->withoutGlobalScopes()->where('source_id', $source->id)->where('device_external_id', $device_external_id);
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
            ], array_keys($aliases), array_values($aliases)),
            ['source_id', 'device_external_id', 'alias'],
            ['name'],
        );
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
