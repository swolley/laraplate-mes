<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;

/**
 * Whether a work center is fed by a machine. It is "connected" when it has an active device, of an
 * active source, with a state signal: its stops are then derived from the machine, so nobody types a
 * downtime for it, and its OEE availability follows ISO 22400.
 */
final class MachineConnectivity
{
    public function isConnected(int $work_center_id): bool
    {
        return MachineDevice::query()
            ->withoutGlobalScopes()
            ->where('work_center_id', $work_center_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('source_id', MachineSource::query()->withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')->select('id'))
            ->whereIn('id', MachineSignal::query()->withoutGlobalScopes()->where('role', SignalRole::State->value)->whereNull('deleted_at')->select('device_id'))
            ->exists();
    }
}
