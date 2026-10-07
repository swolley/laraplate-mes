<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\OperationQuantityAudit;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

it('stores a count row with milliseconds, with or without an operation', function (): void {
    $count = MachineCount::factory()->create(['ts' => '2026-10-05 08:00:00.250', 'good' => 3, 'raw_value' => 103]);

    $fresh = MachineCount::query()->findOrFail($count->id);
    expect($fresh->ts->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.250')
        ->and($fresh->production_order_operation_id)->toBeNull()
        ->and((float) $fresh->good)->toBe(3.0)
        ->and((float) $fresh->scrap)->toBe(0.0);
});

it('refuses a second row of one signal at the same moment', function (): void {
    $first = MachineCount::factory()->create(['ts' => '2026-10-05 08:00:00.000']);

    expect(fn () => MachineCount::factory()->create(['signal_id' => $first->signal_id, 'device_id' => $first->device_id, 'work_center_id' => $first->work_center_id, 'ts' => '2026-10-05 08:00:00.000']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('gives operations the quantity columns with their defaults', function (): void {
    $operation = ProductionOrderOperation::factory()->create();

    $fresh = ProductionOrderOperation::query()->findOrFail($operation->id);
    expect((float) $fresh->machine_good_quantity)->toBe(0.0)
        ->and((float) $fresh->machine_scrap_quantity)->toBe(0.0)
        ->and($fresh->declared_good_quantity)->toBeNull()
        ->and($fresh->declared_scrap_quantity)->toBeNull()
        ->and($fresh->target_reached_at)->toBeNull();
});

it('stores an audit row with old and new values', function (): void {
    $operation = ProductionOrderOperation::factory()->create();

    $audit = OperationQuantityAudit::query()->create(['operation_id' => $operation->id, 'user_id' => null, 'field' => 'declared_good_quantity', 'old_value' => 10, 'new_value' => 12]);

    expect((float) $audit->fresh()->old_value)->toBe(10.0)
        ->and((float) $audit->fresh()->new_value)->toBe(12.0)
        ->and($operation->quantityAudits()->count())->toBe(1);
});
