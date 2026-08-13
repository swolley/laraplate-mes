<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\OperatorLogAction;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\OperatorLog;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\ShiftInstance;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ShiftVerificationService;

uses(RefreshDatabase::class);

it('logs an operator start action when an operation is started', function (): void {
    $operation = ProductionOrderOperation::factory()->create();

    resolve(ProductionOrderOperationService::class)->start($operation);

    $log = OperatorLog::query()
        ->where('production_order_operation_id', $operation->id)
        ->where('action', OperatorLogAction::Started->value)
        ->first();

    expect($log)->not->toBeNull();
});

it('logs both start and complete actions across an operation lifecycle', function (): void {
    $operation = ProductionOrderOperation::factory()->create();
    $service = resolve(ProductionOrderOperationService::class);

    $service->complete($service->start($operation), 15.0);

    expect(OperatorLog::query()->where('production_order_operation_id', $operation->id)->count())->toBe(2)
        ->and($operation->refresh()->status)->toBe(ProductionOrderOperationStatus::Completed);
});

it('attaches the covering shift instance and warns non-blocking when absent', function (): void {
    $withShift = ProductionOrderOperation::factory()->create();
    ShiftInstance::factory()->coveringNow($withShift->work_center_id)->create();

    $withoutShift = ProductionOrderOperation::factory()->create();

    $service = resolve(ShiftVerificationService::class);
    $covered = $service->logOperatorAction($withShift, OperatorLogAction::Started);
    $uncovered = $service->logOperatorAction($withoutShift, OperatorLogAction::Started);

    expect($covered->shift_instance_id)->not->toBeNull()
        ->and($uncovered->shift_instance_id)->toBeNull()
        ->and($service->hasActiveShift($withShift->work_center_id))->toBeTrue()
        ->and($service->hasActiveShift($withoutShift->work_center_id))->toBeFalse();
});

it('averages efficiency across an operator completed operations', function (): void {
    $a = ProductionOrderOperation::factory()->create(['efficiency' => 80, 'status' => ProductionOrderOperationStatus::Completed->value]);
    $b = ProductionOrderOperation::factory()->create(['efficiency' => 100, 'status' => ProductionOrderOperationStatus::Completed->value]);

    OperatorLog::factory()->create(['user_id' => 7, 'production_order_operation_id' => $a->id, 'action' => OperatorLogAction::Completed->value]);
    OperatorLog::factory()->create(['user_id' => 7, 'production_order_operation_id' => $b->id, 'action' => OperatorLogAction::Completed->value]);

    $average = resolve(ShiftVerificationService::class)
        ->averageEfficiencyForOperator(7, now()->subDay(), now()->addDay());

    expect($average)->toBe(90.0);
});

it('returns null average efficiency for an operator with no completed operations', function (): void {
    $average = resolve(ShiftVerificationService::class)
        ->averageEfficiencyForOperator(999, now()->subDay(), now()->addDay());

    expect($average)->toBeNull();
});
