<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Services\QualityCheckPlanner;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * Build a released order whose routing snapshot references a real routing
 * operation, so a QualityPlan can be scoped to that operation's id.
 *
 * @return array{order: ProductionOrder, routing_operation_id: int}
 */
function orderWithOperationSnapshot(): array
{
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $order = ProductionOrder::factory()->released()->create([
        'company_id' => $company->id,
        'quantity_planned' => 5,
    ]);

    $routing = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $order->item_id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    $operation = RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 10,
        'description' => 'Assembly',
        'setup_time_minutes' => 10,
        'cycle_time_minutes' => 2,
    ]);

    $order->update([
        'routing_snapshot' => [
            'id' => $routing->id,
            'version' => $routing->version,
            'operations' => [[
                'routing_operation_id' => $operation->id,
                'work_center_id' => $work_center->id,
                'sequence' => 10,
                'description' => 'Assembly',
                'setup_time_minutes' => 10,
                'cycle_time_minutes' => 2,
                'is_parallel' => false,
            ]],
        ],
    ]);

    return ['order' => $order->refresh(), 'routing_operation_id' => (int) $operation->id];
}

function completeSingleOperation(ProductionOrder $order): Modules\MES\Models\ProductionOrderOperation
{
    $service = resolve(ProductionOrderOperationService::class);
    $operation = $service->start($service->generateForOrder($order)->first());

    return $service->complete($operation, 20.0);
}

it('creates an in-process quality check when an operation completes under an active plan', function (): void {
    ['order' => $order, 'routing_operation_id' => $routing_operation_id] = orderWithOperationSnapshot();
    $plan = QualityPlan::factory()->create([
        'company_id' => $order->company_id,
        'item_id' => $order->item_id,
        'routing_operation_id' => $routing_operation_id,
        'name' => 'Dimensional control',
    ]);
    QualityPlanCharacteristic::factory()->create(['quality_plan_id' => $plan->id]);

    $operation = completeSingleOperation($order);

    $check = QualityCheck::query()->where('production_order_operation_id', $operation->id)->first();

    expect($check)->not->toBeNull()
        ->and($check->quality_plan_id)->toBe($plan->id)
        ->and($check->item_id)->toBe($order->item_id)
        ->and($check->name)->toBe('Dimensional control')
        ->and($check->status)->toBe(QualityCheckStatus::Pending);
});

it('creates no check when no plan targets the operation', function (): void {
    ['order' => $order] = orderWithOperationSnapshot();
    // Only a final-inspection (operation-less) plan exists.
    QualityPlan::factory()->create([
        'company_id' => $order->company_id,
        'item_id' => $order->item_id,
        'routing_operation_id' => null,
    ]);

    completeSingleOperation($order);

    expect(QualityCheck::query()->count())->toBe(0);
});

it('does not duplicate the in-process check on replay', function (): void {
    ['order' => $order, 'routing_operation_id' => $routing_operation_id] = orderWithOperationSnapshot();
    QualityPlan::factory()->create([
        'company_id' => $order->company_id,
        'item_id' => $order->item_id,
        'routing_operation_id' => $routing_operation_id,
    ]);

    $operation = completeSingleOperation($order);
    resolve(QualityCheckPlanner::class)->forOperation($operation->refresh());

    expect(QualityCheck::query()->where('production_order_operation_id', $operation->id)->count())->toBe(1);
});

it('creates a final-inspection check when an order completes under a plan', function (): void {
    $company = MesTestHelpers::makeCompany();
    $order = ProductionOrder::factory()->released()->create(['company_id' => $company->id]);
    $plan = QualityPlan::factory()->create([
        'company_id' => $order->company_id,
        'item_id' => $order->item_id,
        'routing_operation_id' => null,
        'name' => 'Final inspection',
    ]);

    resolve(ProductionOrderService::class)->complete($order, 5.0);

    $check = QualityCheck::query()
        ->where('production_order_id', $order->id)
        ->whereNull('production_order_operation_id')
        ->first();

    expect($check)->not->toBeNull()
        ->and($check->quality_plan_id)->toBe($plan->id)
        ->and($check->name)->toBe('Final inspection')
        ->and($check->status)->toBe(QualityCheckStatus::Pending);
});

it('does not create a final check when only an operation-scoped plan exists', function (): void {
    ['order' => $order, 'routing_operation_id' => $routing_operation_id] = orderWithOperationSnapshot();
    QualityPlan::factory()->create([
        'company_id' => $order->company_id,
        'item_id' => $order->item_id,
        'routing_operation_id' => $routing_operation_id, // operation-scoped, not final
    ]);

    resolve(ProductionOrderService::class)->complete($order, 5.0);

    expect(QualityCheck::query()
        ->where('production_order_id', $order->id)
        ->whereNull('production_order_operation_id')
        ->count())->toBe(0);
});
