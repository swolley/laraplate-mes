<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Illuminate\Support\Facades\Cache;
use Modules\MES\Machine\Data\ResolvedSignal;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSource;

/**
 * Maps `(source, device external id, signal key)` to the configured device and signal. The
 * active devices of a source, with their signals, are read in one pass and cached; saving or
 * deleting a device or a signal forgets the map ({@see \Modules\MES\Observers\MachineConfigurationObserver}).
 * An instance also keeps the maps it read, so a job resolving thousands of samples reads each once:
 * resolve through the container per job, not as a long-lived singleton.
 */
final class SignalResolver
{
    /**
     * @var array<int, array<string, MachineDevice>>
     */
    private array $maps = [];

    public function resolve(MachineSource $source, string $device_external_id, string $signal_key): ?ResolvedSignal
    {
        $device = $this->map($source->id)[$device_external_id] ?? null;
        $signal = $device?->signals->firstWhere('key', $signal_key);

        if ($device === null || $signal === null) {
            return null;
        }

        return new ResolvedSignal($device, $signal, (int) $device->work_center_id, (int) $device->company_id);
    }

    public function forget(int $source_id): void
    {
        unset($this->maps[$source_id]);
        Cache::forget($this->cacheKey($source_id));
    }

    /**
     * @return array<string, MachineDevice> the active devices of the source by external id, signals loaded
     */
    private function map(int $source_id): array
    {
        return $this->maps[$source_id] ??= Cache::rememberForever($this->cacheKey($source_id), static fn (): array => MachineDevice::query()
            ->withoutGlobalScopes()
            ->where('source_id', $source_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with(['signals' => static fn ($query) => $query->withoutGlobalScopes()->whereNull('deleted_at')])
            ->get()
            ->keyBy('external_id')
            ->all());
    }

    private function cacheKey(int $source_id): string
    {
        return "mes:machine:map:{$source_id}";
    }
}
