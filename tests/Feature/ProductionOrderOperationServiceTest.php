<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * Build a draft order whose routing snapshot carries a single operation.
 */
function orderWithSnapshotOperation(int $setup = 10, float $cycle = 2, float $quantity = 5): ProductionOrder
{
    $company = MesTestHelpers::makeCompany();
    $work_center = Modules\MES\Models\WorkCenter::factory()->create(['company_id' => $company->id]);

    return ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => $quantity,
        'routing_snapshot' => [
            'id' => 1,
            'version' => 'v1',
            'operations' => [[
                'routing_operation_id' => 99,
                'work_center_id' => $work_center->id,
                'sequence' => 10,
                'description' => 'Assembly',
                'setup_time_minutes' => $setup,
                'cycle_time_minutes' => $cycle,
                'is_parallel' => false,
            ]],
        ],
    ]);
}

it('generates operations from the routing snapshot', function (): void {
    $order = orderWithSnapshotOperation();

    $operations = resolve(ProductionOrderOperationService::class)->generateForOrder($order);

    expect($operations)->toHaveCount(1)
        ->and($operations->first()->status)->toBe(ProductionOrderOperationStatus::Planned)
        ->and($operations->first()->sequence)->toBe(10)
        ->and($operations->first()->routing_operation_id)->toBe(99);
});

it('starts a planned operation', function (): void {
    $order = orderWithSnapshotOperation();
    $operation = resolve(ProductionOrderOperationService::class)->generateForOrder($order)->first();

    $started = resolve(ProductionOrderOperationService::class)->start($operation);

    expect($started->status)->toBe(ProductionOrderOperationStatus::InProgress)
        ->and($started->actual_start_at)->not->toBeNull();
});

it('computes efficiency as standard over actual percent', function (): void {
    // standard = setup 10 + cycle 2 * qty 5 = 20 min; actual 30 min => 66.67%.
    $order = orderWithSnapshotOperation(setup: 10, cycle: 2, quantity: 5);
    $service = resolve(ProductionOrderOperationService::class);
    $operation = $service->start($service->generateForOrder($order)->first());

    $completed = $service->complete($operation, 30.0);

    expect($completed->status)->toBe(ProductionOrderOperationStatus::Completed)
        ->and(round((float) $completed->efficiency, 2))->toBe(66.67)
        ->and((float) $completed->actual_minutes)->toBe(30.0);
});

it('clamps efficiency to the upper bound', function (): void {
    $order = orderWithSnapshotOperation(setup: 1000, cycle: 1000, quantity: 100);
    $service = resolve(ProductionOrderOperationService::class);
    $operation = $service->start($service->generateForOrder($order)->first());

    $completed = $service->complete($operation, 1.0);

    expect((float) $completed->efficiency)->toBe(999.99);
});

it('refuses to complete an operation that is not in progress', function (): void {
    $operation = ProductionOrderOperation::factory()->create();

    expect(fn () => resolve(ProductionOrderOperationService::class)->complete($operation))
        ->toThrow(DomainException::class);
});

it('skips an operation', function (): void {
    $operation = ProductionOrderOperation::factory()->create();

    $skipped = resolve(ProductionOrderOperationService::class)->skip($operation);

    expect($skipped->status)->toBe(ProductionOrderOperationStatus::Skipped);
});

it('allows parallel operations on the same sequence to run concurrently', function (): void {
    $order = orderWithSnapshotOperation();
    $work_center_id = $order->routing_snapshot['operations'][0]['work_center_id'];

    $a = ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $work_center_id,
        'sequence' => 10,
        'is_parallel' => true,
    ]);
    $b = ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $work_center_id,
        'sequence' => 10,
        'is_parallel' => true,
    ]);

    $service = resolve(ProductionOrderOperationService::class);
    $service->start($a);
    $service->start($b);

    expect($a->refresh()->status)->toBe(ProductionOrderOperationStatus::InProgress)
        ->and($b->refresh()->status)->toBe(ProductionOrderOperationStatus::InProgress);
});
