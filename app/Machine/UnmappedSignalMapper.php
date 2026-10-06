<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\UnmappedSignal;

/**
 * Turns an unmapped signal into a configured one, in place: the device is created when it does not
 * exist yet, the signal is validated by its model rules, and the unmapped row goes away.
 */
final class UnmappedSignalMapper
{
    /**
     * @param  array<string, mixed>  $attributes  `work_center_id`, `role`, `data_type`, `unit`, `config`, `quality_plan_characteristic_id`
     *
     * @throws ValidationException when the signal is invalid, the device is unknown and no work center
     *                             is given, or the row is a raw state value (fixed in the state signal's map).
     */
    public function map(UnmappedSignal $unmapped, array $attributes): MachineSignal
    {
        if (str_contains($unmapped->signal_key, '#')) {
            throw ValidationException::withMessages(['signal_key' => ['A raw state value is fixed in the map of the state signal, not mapped as a signal.']]);
        }

        return $unmapped->getConnection()->transaction(function () use ($unmapped, $attributes): MachineSignal {
            $device = MachineDevice::query()
                ->where('source_id', $unmapped->source_id)
                ->where('external_id', $unmapped->device_external_id)
                ->first();

            if (! $device instanceof MachineDevice) {
                $work_center_id = $attributes['work_center_id'] ?? null;

                if (! is_numeric($work_center_id)) {
                    throw ValidationException::withMessages(['work_center_id' => ['The device is not configured yet: give it a work center.']]);
                }

                $device = MachineDevice::query()->create([
                    'company_id' => $unmapped->company_id,
                    'source_id' => $unmapped->source_id,
                    'external_id' => $unmapped->device_external_id,
                    'work_center_id' => (int) $work_center_id,
                ]);
            }

            $signal = MachineSignal::query()->create([
                'company_id' => $device->company_id,
                'device_id' => $device->id,
                'key' => $unmapped->signal_key,
                'role' => Arr::string($attributes, 'role'),
                'data_type' => isset($attributes['data_type']) ? Arr::string($attributes, 'data_type') : 'number',
                'unit' => isset($attributes['unit']) ? Arr::string($attributes, 'unit') : null,
                'config' => is_array($attributes['config'] ?? null) ? $attributes['config'] : null,
                'quality_plan_characteristic_id' => is_numeric($attributes['quality_plan_characteristic_id'] ?? null) ? (int) $attributes['quality_plan_characteristic_id'] : null,
            ]);

            $unmapped->delete();

            return $signal;
        });
    }
}
