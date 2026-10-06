<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineProfile;
use Modules\MES\Models\MachineSignal;

/**
 * Machine profiles as data. A profile's `definition` is:
 *
 * `{"signals": [{"key", "role", "data_type", "unit"?, "config"?}], "state_map"?: {raw: state},
 * "alarm_map"?: {code: cause}, "default_causes"?: {state: cause}}`.
 *
 * Applying a profile copies its signals onto a device; the maps are copied into the State and Alarm
 * signals that have none. Measurement signals are not part of a profile: they point at a quality plan
 * characteristic, which is company data, so they are mapped on the device.
 */
final class MachineProfileService
{
    /**
     * Copies the profile's signals onto the device and records the profile version. Signals the device
     * already has, and their local edits, are left as they are.
     */
    public function apply(MachineDevice $device, MachineProfile $profile): MachineDevice
    {
        $device->getConnection()->transaction(function () use ($device, $profile): void {
            $definition = $profile->definition;
            $existing = $device->signals()->pluck('key')->all();

            foreach ($this->list($definition['signals'] ?? []) as $signal) {
                if (in_array($signal['key'], $existing, true)) {
                    continue;
                }

                MachineSignal::query()->create([
                    'company_id' => $device->company_id,
                    'device_id' => $device->id,
                    'key' => $signal['key'],
                    'role' => $signal['role'],
                    'data_type' => $signal['data_type'],
                    'unit' => $signal['unit'] ?? null,
                    'config' => $this->configFor($signal, $definition),
                ]);
            }

            $device->update(['machine_profile_id' => $profile->id, 'profile_version' => $profile->version]);
        });

        return $device->refresh();
    }

    public function export(MachineProfile $profile): string
    {
        return json_encode([
            'vendor' => $profile->vendor,
            'model' => $profile->model,
            'version' => $profile->version,
            'definition' => $profile->definition,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ValidationException when the file is not JSON, breaks the profile rules, or repeats a
     *                             `(vendor, model, version)` the company already has.
     */
    public function import(int $company_id, string $json): MachineProfile
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => ['The file is not valid JSON.']]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages(['file' => ['The file is not a JSON object.']]);
        }

        $this->validateDefinition($data);

        return MachineProfile::query()->create([
            'company_id' => $company_id,
            'vendor' => $data['vendor'],
            'model' => $data['model'],
            'version' => $data['version'],
            'definition' => $data['definition'],
        ]);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function validateDefinition(array $data): void
    {
        Validator::make($data, [
            'vendor' => ['required', 'string', 'max:128'],
            'model' => ['required', 'string', 'max:128'],
            'version' => ['required', 'string', 'max:32'],
            'definition' => ['required', 'array'],
            'definition.signals' => ['required', 'array'],
            'definition.state_map' => ['sometimes', 'array'],
            'definition.state_map.*' => ['string', Rule::in(MachineState::values())],
            'definition.alarm_map' => ['sometimes', 'array'],
            'definition.alarm_map.*' => ['string', Rule::in(DowntimeCause::values())],
            'definition.default_causes' => ['sometimes', 'array'],
            'definition.default_causes.*' => ['string', Rule::in(DowntimeCause::values())],
            'definition.signals.*.key' => ['required', 'string', 'max:128', 'distinct'],
            'definition.signals.*.role' => ['required', 'string', Rule::in(array_values(array_diff(SignalRole::values(), [SignalRole::Measurement->value])))],
            'definition.signals.*.data_type' => ['required', Rule::in(['number', 'boolean', 'string'])],
            'definition.signals.*.unit' => ['nullable', 'string', 'max:16'],
        ])->validate();

        $definition = Arr::array($data, 'definition');

        foreach ($this->list($definition['signals'] ?? []) as $index => $signal) {
            $role = SignalRole::from(Arr::string($signal, 'role'));
            $state_or_alarm = $role === SignalRole::State || $role === SignalRole::Alarm;
            $has_map = $role === SignalRole::State ? isset($definition['state_map']) : isset($definition['alarm_map']);
            $config = $this->configFor($signal, $definition);

            Validator::make(['config' => $config], array_diff_key(MachineSignal::rulesForRole($role), ['quality_plan_characteristic_id' => true]))
                ->after(static function ($validator) use ($state_or_alarm, $has_map, $config, $index): void {
                    if ($state_or_alarm && ! $has_map && ($config['map'] ?? []) === []) {
                        $validator->errors()->add("definition.signals.{$index}.config", 'A state or alarm signal needs a map, its own or the profile\'s.');
                    }
                })
                ->validate();
        }
    }

    /**
     * The signal's own config, with the profile's state or alarm map as the default of the map.
     *
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function configFor(array $signal, array $definition): ?array
    {
        $config = is_array($signal['config'] ?? null) ? $signal['config'] : [];
        $role = SignalRole::tryFrom(is_string($signal['role'] ?? null) ? $signal['role'] : '');

        if (! isset($config['map'])) {
            $map = match ($role) {
                SignalRole::State => $definition['state_map'] ?? null,
                SignalRole::Alarm => $definition['alarm_map'] ?? null,
                default => null,
            };

            if (is_array($map)) {
                $config['map'] = $map;
            }
        }

        return $config === [] ? null : $config;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $signals): array
    {
        $list = [];

        foreach (is_array($signals) ? $signals : [] as $signal) {
            if (is_array($signal)) {
                $list[] = array_combine(array_map(strval(...), array_keys($signal)), array_values($signal));
            }
        }

        return $list;
    }
}
