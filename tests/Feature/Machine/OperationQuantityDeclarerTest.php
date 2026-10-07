<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\OperationQuantityAudit;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\MachineCountAssigner;
use Modules\MES\Services\OperationQuantityDeclarer;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

function runningOperation(array $attributes = []): ProductionOrderOperation
{
    return ProductionOrderOperation::factory()->create($attributes + ['status' => ProductionOrderOperationStatus::InProgress->value, 'actual_start_at' => now()->subHour()]);
}

it('prefills the declared quantities from the machine ones when the operation completes', function (): void {
    $operation = runningOperation(['machine_good_quantity' => 95, 'machine_scrap_quantity' => 5]);

    resolve(ProductionOrderOperationService::class)->complete($operation);

    $fresh = $operation->fresh();
    expect((float) $fresh->declared_good_quantity)->toBe(95.0)
        ->and((float) $fresh->declared_scrap_quantity)->toBe(5.0)
        ->and($fresh->quantityAudits()->count())->toBe(0);
});

it('leaves declared quantities alone when they are set, and null when the machine counted nothing', function (): void {
    $declared = runningOperation(['machine_good_quantity' => 95, 'declared_good_quantity' => 90, 'declared_scrap_quantity' => 1]);
    $uncounted = runningOperation();
    $service = resolve(ProductionOrderOperationService::class);

    $service->complete($declared);
    $service->complete($uncounted);

    expect((float) $declared->fresh()->declared_good_quantity)->toBe(90.0)
        ->and((float) $declared->fresh()->declared_scrap_quantity)->toBe(1.0)
        ->and($uncounted->fresh()->declared_good_quantity)->toBeNull();
});

it('audits every changed declared quantity with old value, new value and user', function (): void {
    $operation = runningOperation(['declared_good_quantity' => 95]);
    $user = user_class()::factory()->create();

    resolve(OperationQuantityDeclarer::class)->declare($operation, 92.0, 4.0, $user->id);

    $audits = OperationQuantityAudit::query()->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->field)->toBe('declared_good_quantity')
        ->and((float) $audits[0]->old_value)->toBe(95.0)
        ->and((float) $audits[0]->new_value)->toBe(92.0)
        ->and($audits[0]->user_id)->toBe($user->id)
        ->and($audits[1]->field)->toBe('declared_scrap_quantity')
        ->and($audits[1]->old_value)->toBeNull()
        ->and((float) $audits[1]->new_value)->toBe(4.0)
        ->and((float) $operation->fresh()->declared_good_quantity)->toBe(92.0);
});

it('writes no audit row when nothing changes, and refuses a negative quantity', function (): void {
    $operation = runningOperation(['declared_good_quantity' => 95]);
    $declarer = resolve(OperationQuantityDeclarer::class);

    $declarer->declare($operation, 95.0, null);

    expect(OperationQuantityAudit::query()->count())->toBe(0)
        ->and(fn () => $declarer->declare($operation, -1.0, null))->toThrow(DomainException::class)
        ->and(OperationQuantityAudit::query()->count())->toBe(0);
});

it('assigns the unattributed counts of the work center inside the range, and only those', function (): void {
    $operation = runningOperation(['production_order_id' => Modules\MES\Models\ProductionOrder::factory()->create(['quantity_planned' => 1000])->id]);
    $other_operation = runningOperation();
    $inside = MachineCount::factory()->create(['work_center_id' => $operation->work_center_id, 'ts' => '2026-10-05 08:30:00', 'good' => 10]);
    $at_end = MachineCount::factory()->create(['work_center_id' => $operation->work_center_id, 'ts' => '2026-10-05 09:00:00', 'good' => 7]);
    $before = MachineCount::factory()->create(['work_center_id' => $operation->work_center_id, 'ts' => '2026-10-05 07:59:59', 'good' => 3]);
    $elsewhere = MachineCount::factory()->create(['work_center_id' => WorkCenter::factory()->create()->id, 'ts' => '2026-10-05 08:30:00', 'good' => 4]);
    $attributed = MachineCount::factory()->create(['work_center_id' => $operation->work_center_id, 'production_order_operation_id' => $other_operation->id, 'ts' => '2026-10-05 08:40:00', 'good' => 5]);

    $assigned = resolve(MachineCountAssigner::class)->assign($operation->id, Carbon\CarbonImmutable::parse('2026-10-05 08:00:00'), Carbon\CarbonImmutable::parse('2026-10-05 09:00:00'));

    expect($assigned)->toBe(1)
        ->and($inside->fresh()->production_order_operation_id)->toBe($operation->id)
        ->and($at_end->fresh()->production_order_operation_id)->toBeNull()
        ->and($before->fresh()->production_order_operation_id)->toBeNull()
        ->and($elsewhere->fresh()->production_order_operation_id)->toBeNull()
        ->and($attributed->fresh()->production_order_operation_id)->toBe($other_operation->id)
        ->and((float) $operation->fresh()->machine_good_quantity)->toBe(10.0);
});
