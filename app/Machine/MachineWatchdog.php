<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineSource;

/**
 * Notices devices that stopped sending. A device silent for longer than its source's
 * `heartbeat_timeout_seconds` opens one `device_silent` incident, which closes when the device is
 * heard again. The synthetic `Offline` state interval belongs to the state step, and a stopped bridge
 * to the MQTT step.
 */
final class MachineWatchdog
{
    public function __construct(
        private readonly MachineIncidentRecorder $incidents,
    ) {}

    /**
     * @return int how many incidents were opened
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $open = MachineIncident::query()
            ->withoutGlobalScopes()
            ->unresolved()
            ->where('type', MachineIncidentType::DeviceSilent->value)
            ->pluck('device_id')
            ->all();
        $opened = 0;

        $devices = MachineDevice::query()
            ->where('is_active', true)
            ->whereHas('source', static fn ($sources) => $sources->where('is_active', true))
            ->with('source')
            ->get();

        foreach ($devices as $device) {
            $source = $device->source;

            if (! $source instanceof MachineSource) {
                continue;
            }

            $seen = CarbonImmutable::instance($device->last_seen_at ?? $device->created_at);
            $silent_seconds = (int) $seen->diffInSeconds($now, true);
            $is_silent = $silent_seconds > $source->heartbeat_timeout_seconds;
            $has_incident = in_array($device->id, $open, true);

            if ($is_silent && ! $has_incident) {
                $this->incidents->record($source, MachineIncidentType::DeviceSilent, [
                    'device' => $device->external_id,
                    'silent_seconds' => $silent_seconds,
                ], $device, $now);
                $opened++;
            } elseif (! $is_silent && $has_incident) {
                $this->incidents->resolve($source, MachineIncidentType::DeviceSilent, $device);
            }
        }

        return $opened;
    }
}
