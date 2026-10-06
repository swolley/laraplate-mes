<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Machine\SignalResolver;
use Modules\MES\Machine\UnmappedSignalMapper;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

it('maps an unmapped signal of an existing device and removes the unmapped row', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $device->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'spindle']);

    $signal = resolve(UnmappedSignalMapper::class)->map($unmapped, ['role' => SignalRole::ProcessValue->value, 'data_type' => 'number', 'unit' => 'rpm']);

    expect($signal->device_id)->toBe($device->id)
        ->and($signal->key)->toBe('spindle')
        ->and($signal->unit)->toBe('rpm')
        ->and(UnmappedSignal::query()->count())->toBe(0);
});

it('creates the device first when it is unknown, under the given work center', function (): void {
    $source = MachineSource::factory()->create();
    $work_center = WorkCenter::factory()->create();
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $source->id, 'device_external_id' => 'new-machine', 'signal_key' => 'temp']);

    $signal = resolve(UnmappedSignalMapper::class)->map($unmapped, ['work_center_id' => $work_center->id, 'role' => SignalRole::ProcessValue->value, 'data_type' => 'number']);

    $device = $signal->device;
    expect($device->external_id)->toBe('new-machine')
        ->and($device->source_id)->toBe($source->id)
        ->and($device->work_center_id)->toBe($work_center->id)
        ->and($device->company_id)->toBe($source->company_id);
});

it('creates nothing when the device is unknown and no work center is given', function (): void {
    $unmapped = UnmappedSignal::factory()->create(['device_external_id' => 'new-machine', 'signal_key' => 'temp']);

    expect(fn () => resolve(UnmappedSignalMapper::class)->map($unmapped, ['role' => SignalRole::ProcessValue->value, 'data_type' => 'number']))->toThrow(ValidationException::class);
    expect(MachineDevice::query()->count())->toBe(0)
        ->and(UnmappedSignal::query()->count())->toBe(1);
});

it('refuses an invalid signal and keeps the unmapped row', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $device->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'parts']);

    expect(fn () => resolve(UnmappedSignalMapper::class)->map($unmapped, ['role' => SignalRole::GoodCount->value, 'data_type' => 'number']))->toThrow(ValidationException::class);
    expect(UnmappedSignal::query()->count())->toBe(1);
});

it('refuses a raw state value row, which is fixed in the state signal map', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $device->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'state#HOLDING']);

    expect(fn () => resolve(UnmappedSignalMapper::class)->map($unmapped, ['role' => SignalRole::ProcessValue->value, 'data_type' => 'string']))->toThrow(ValidationException::class);
});

it('makes the signal resolvable at once', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $device->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'spindle']);
    expect(resolve(SignalResolver::class)->resolve($device->source, 'press-07', 'spindle'))->toBeNull();

    resolve(UnmappedSignalMapper::class)->map($unmapped, ['role' => SignalRole::ProcessValue->value, 'data_type' => 'number']);

    expect(resolve(SignalResolver::class)->resolve($device->source, 'press-07', 'spindle'))->not->toBeNull();
});
