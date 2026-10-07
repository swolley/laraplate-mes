<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

it('stores an unattributed measurement with milliseconds and refuses a second one at the same moment', function (): void {
    $row = UnattributedMeasurement::factory()->create(['ts' => '2026-10-05 08:00:00.125', 'value' => 10.25, 'serial' => 'SN-1', 'context' => ['lot' => 'L1']]);

    $fresh = UnattributedMeasurement::query()->findOrFail($row->id);
    expect($fresh->ts->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.125')
        ->and((float) $fresh->value)->toBe(10.25)
        ->and($fresh->context)->toBe(['lot' => 'L1'])
        ->and($fresh->assigned_at)->toBeNull()
        ->and($fresh->production_order_operation_id)->toBeNull();

    expect(fn () => UnattributedMeasurement::factory()->create(['signal_id' => $row->signal_id, 'device_id' => $row->device_id, 'work_center_id' => $row->work_center_id, 'ts' => '2026-10-05 08:00:00.125']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('defaults a characteristic to one required sample', function (): void {
    $characteristic = QualityPlanCharacteristic::factory()->create();

    expect(QualityPlanCharacteristic::query()->findOrFail($characteristic->id)->required_samples)->toBe(1);
});

it('keeps manual measurements as they were and accepts the machine columns', function (): void {
    $manual = QualityCheckMeasurement::factory()->create();
    $machine = QualityCheckMeasurement::factory()->create([
        'source' => 'machine',
        'serial' => 'SN-9',
        'measured_at' => '2026-10-05 08:00:00.500',
        'quality_plan_characteristic_id' => QualityPlanCharacteristic::factory()->create()->id,
    ]);

    expect(QualityCheckMeasurement::query()->findOrFail($manual->id)->source)->toBe('manual')
        ->and(QualityCheckMeasurement::query()->findOrFail($manual->id)->machine_signal_id)->toBeNull()
        ->and(QualityCheckMeasurement::query()->findOrFail($machine->id)->measured_at->format('H:i:s.v'))->toBe('08:00:00.500');
});
