<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineProfile;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

it('persists a source with its defaults', function (): void {
    $source = MachineSource::factory()->create();

    expect($source->normalizer)->toBe('canonical')
        ->and($source->transport)->toBe(MachineTransport::Http)
        ->and($source->heartbeat_timeout_seconds)->toBe(120)
        ->and($source->protocol_version)->toBe('1')
        ->and($source->is_active)->toBeTrue();
});

it('refuses two sources with the same code in one company, and allows it across companies', function (): void {
    $source = MachineSource::factory()->create(['code' => 'gw-1']);

    expect(fn () => MachineSource::factory()->create(['company_id' => $source->company_id, 'code' => 'gw-1']))
        ->toThrow(ValidationException::class);

    $other = MesTestHelpers::makeCompany();
    expect(MachineSource::factory()->create(['company_id' => $other->id, 'code' => 'gw-1'])->exists)->toBeTrue();
});

it('refuses a duplicate device external id on a source', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);

    expect(fn () => MachineDevice::factory()->create(['source_id' => $device->source_id, 'external_id' => 'press-07']))
        ->toThrow(ValidationException::class);
});

it('refuses a duplicate signal key on a device', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'good_count']);

    expect(fn () => MachineSignal::factory()->create(['device_id' => $signal->device_id, 'key' => 'good_count']))
        ->toThrow(ValidationException::class);
});

it('keeps a profile version unique per vendor and model in a company', function (): void {
    $profile = MachineProfile::factory()->create(['vendor' => 'Acme', 'model' => 'P1', 'version' => '1.0']);

    expect(fn () => MachineProfile::factory()->create(['company_id' => $profile->company_id, 'vendor' => 'Acme', 'model' => 'P1', 'version' => '1.0']))
        ->toThrow(ValidationException::class);
    expect(MachineProfile::factory()->create(['company_id' => $profile->company_id, 'vendor' => 'Acme', 'model' => 'P1', 'version' => '1.1'])->exists)->toBeTrue();
});

it('rejects a signal configuration that breaks its role rules', function (array $invalid): void {
    expect(fn () => MachineSignal::factory()->create($invalid))->toThrow(ValidationException::class);
})->with([
    'state map with an unknown state' => [['role' => SignalRole::State->value, 'config' => ['map' => ['EXECUTE' => 'flying']]]],
    'state without a map' => [['role' => SignalRole::State->value, 'config' => []]],
    'alarm map with an unknown cause' => [['role' => SignalRole::Alarm->value, 'config' => ['map' => ['E1' => 'gremlins']]]],
    'count without a mode' => [['role' => SignalRole::GoodCount->value, 'config' => []]],
    'count with an unknown mode' => [['role' => SignalRole::GoodCount->value, 'config' => ['mode' => 'sometimes']]],
    'rollover of zero' => [['role' => SignalRole::TotalCount->value, 'config' => ['mode' => 'cumulative', 'rollover_max' => 0]]],
    'process range with min above max' => [['role' => SignalRole::ProcessValue->value, 'config' => ['min' => 10, 'max' => 5]]],
    'measurement without a characteristic' => [['role' => SignalRole::Measurement->value, 'config' => null, 'quality_plan_characteristic_id' => null]],
    'state with a characteristic' => [['role' => SignalRole::State->value, 'config' => ['map' => ['EXECUTE' => 'running']], 'quality_plan_characteristic_id' => 1]],
    'reference with a config' => [['role' => SignalRole::OrderReference->value, 'config' => ['x' => 1]]],
]);

it('accepts a valid configuration for every role', function (): void {
    $characteristic = QualityPlanCharacteristic::factory()->create();

    $valid = [
        [SignalRole::State->value, ['map' => ['EXECUTE' => 'running', 'STOPPED' => 'stopped']], null],
        [SignalRole::Alarm->value, ['map' => ['E1' => 'breakdown']], null],
        [SignalRole::GoodCount->value, ['mode' => 'cumulative', 'rollover_max' => 65535], null],
        [SignalRole::ScrapCount->value, ['mode' => 'delta'], null],
        [SignalRole::ProcessValue->value, ['min' => 10, 'max' => 90], null],
        [SignalRole::OrderReference->value, null, null],
        [SignalRole::Measurement->value, null, $characteristic->id],
    ];

    foreach ($valid as $index => [$role, $config, $characteristic_id]) {
        expect(MachineSignal::factory()->create(['key' => "k{$index}", 'role' => $role, 'config' => $config, 'quality_plan_characteristic_id' => $characteristic_id])->exists)->toBeTrue();
    }
});

it('lets a source issue a Sanctum token', function (): void {
    $source = MachineSource::factory()->create();

    $token = $source->createToken('t', ['mes:machine-ingest'])->plainTextToken;

    expect($token)->not->toBe('')
        ->and($source->tokens()->count())->toBe(1);
});
