<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;

/**
 * Why a machine stopped, in order: what the alarm map of the device says about the alarm code of the
 * stop, the default of the state (setup is a setup, maintenance is planned maintenance), and finally
 * `Unclassified`, which the operator sorts out later.
 */
final class DowntimeCauseResolver
{
    public function resolve(MachineDevice $device, MachineState $state, ?string $alarm_code): DowntimeCause
    {
        if ($alarm_code !== null) {
            foreach ($device->signals()->where('role', SignalRole::Alarm->value)->get() as $signal) {
                $map = $signal->config['map'] ?? null;
                $mapped = is_array($map) ? ($map[$alarm_code] ?? null) : null;
                $cause = is_string($mapped) ? DowntimeCause::tryFrom($mapped) : null;

                if ($cause instanceof DowntimeCause) {
                    return $cause;
                }
            }
        }

        return match ($state) {
            MachineState::Setup => DowntimeCause::Setup,
            MachineState::Maintenance => DowntimeCause::PlannedMaintenance,
            default => DowntimeCause::Unclassified,
        };
    }
}
