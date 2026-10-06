<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Machine\MachineProfileService;
use Modules\MES\Machine\SignalResolver;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineProfile;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

function profileDefinition(): array
{
    return [
        'signals' => [
            ['key' => 'exec', 'role' => 'state', 'data_type' => 'string'],
            ['key' => 'alarm', 'role' => 'alarm', 'data_type' => 'string'],
            ['key' => 'parts', 'role' => 'good_count', 'data_type' => 'number', 'unit' => 'pcs', 'config' => ['mode' => 'cumulative', 'rollover_max' => 65535]],
        ],
        'state_map' => ['EXECUTE' => 'running', 'STOPPED' => 'stopped'],
        'alarm_map' => ['E1' => 'breakdown'],
        'default_causes' => ['setup' => 'setup'],
    ];
}

it('copies the signals of a profile onto a device and records the version', function (): void {
    $profile = MachineProfile::factory()->create(['version' => '2.1', 'definition' => profileDefinition()]);
    $device = MachineDevice::factory()->create();

    $applied = resolve(MachineProfileService::class)->apply($device, $profile);

    $signals = $applied->signals()->orderBy('key')->get()->keyBy('key');
    expect($signals->keys()->all())->toBe(['alarm', 'exec', 'parts'])
        ->and($signals['parts']->config)->toBe(['mode' => 'cumulative', 'rollover_max' => 65535])
        ->and($signals['parts']->unit)->toBe('pcs')
        ->and($signals['exec']->config)->toBe(['map' => ['EXECUTE' => 'running', 'STOPPED' => 'stopped']])
        ->and($signals['alarm']->config)->toBe(['map' => ['E1' => 'breakdown']])
        ->and($applied->machine_profile_id)->toBe($profile->id)
        ->and($applied->profile_version)->toBe('2.1');
});

it('changes nothing on a second apply and keeps local edits', function (): void {
    $profile = MachineProfile::factory()->create(['definition' => profileDefinition()]);
    $device = MachineDevice::factory()->create();
    $service = resolve(MachineProfileService::class);
    $service->apply($device, $profile);
    $device->signals()->where('key', 'parts')->first()->update(['unit' => 'boxes']);

    $service->apply($device->fresh(), $profile);

    expect($device->signals()->count())->toBe(3)
        ->and($device->signals()->where('key', 'parts')->first()->unit)->toBe('boxes');
});

it('does not overwrite a map the signal already has', function (): void {
    $profile = MachineProfile::factory()->create(['definition' => profileDefinition()]);
    $device = MachineDevice::factory()->create();
    MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'exec', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]);

    resolve(MachineProfileService::class)->apply($device, $profile);

    expect($device->signals()->where('key', 'exec')->first()->config)->toBe(['map' => ['RUN' => 'running']]);
});

it('makes the new signals resolvable at once', function (): void {
    $profile = MachineProfile::factory()->create(['definition' => profileDefinition()]);
    $device = MachineDevice::factory()->create();
    expect(resolve(SignalResolver::class)->resolve($device->source, $device->external_id, 'parts'))->toBeNull();

    resolve(MachineProfileService::class)->apply($device, $profile);

    expect(resolve(SignalResolver::class)->resolve($device->source, $device->external_id, 'parts'))->not->toBeNull();
});

it('refuses to import a profile that breaks its rules, creating nothing', function (array $definition): void {
    $json = json_encode(['vendor' => 'Acme', 'model' => 'P1', 'version' => '1', 'definition' => $definition], JSON_THROW_ON_ERROR);

    expect(fn () => resolve(MachineProfileService::class)->import(MesTestHelpers::makeCompany()->id, $json))->toThrow(ValidationException::class);
    expect(MachineProfile::query()->count())->toBe(0);
})->with([
    'unknown role' => [['signals' => [['key' => 'a', 'role' => 'psychic', 'data_type' => 'number']]]],
    'measurement role' => [['signals' => [['key' => 'a', 'role' => 'measurement', 'data_type' => 'number']]]],
    'count without a mode' => [['signals' => [['key' => 'a', 'role' => 'good_count', 'data_type' => 'number']]]],
    'state map with an unknown state' => [['signals' => [], 'state_map' => ['X' => 'flying']]],
    'alarm map with an unknown cause' => [['signals' => [], 'alarm_map' => ['E1' => 'gremlins']]],
    'duplicate keys' => [['signals' => [['key' => 'a', 'role' => 'order_reference', 'data_type' => 'string'], ['key' => 'a', 'role' => 'order_reference', 'data_type' => 'string']]]],
    'signals not a list' => [['signals' => 'none']],
]);

it('refuses an import that is not JSON', function (): void {
    expect(fn () => resolve(MachineProfileService::class)->import(MesTestHelpers::makeCompany()->id, '{broken'))->toThrow(ValidationException::class);
});

it('exports a profile and imports it back into another company', function (): void {
    $profile = MachineProfile::factory()->create(['vendor' => 'Acme', 'model' => 'P1', 'version' => '1.0', 'definition' => profileDefinition()]);
    $service = resolve(MachineProfileService::class);
    $other = MesTestHelpers::makeCompany();

    $imported = $service->import($other->id, $service->export($profile));

    expect($imported->company_id)->toBe($other->id)
        ->and($imported->definition)->toEqual($profile->definition)
        ->and($imported->version)->toBe('1.0');
    expect(fn () => $service->import($other->id, $service->export($profile)))->toThrow(ValidationException::class);
});
