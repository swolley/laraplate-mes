<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Role;
use Modules\MES\Events\CapacityOverloadDetected;
use Modules\MES\Listeners\NotifyCapacityOverload;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Notifications\CapacityOverloadNotification;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\WorkCenterKpiMaterializer;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

/**
 * A planned operation on a work center whose day load (2 x 2 minutes x 400) exceeds 480 minutes.
 */
function overloadedOperation(): ProductionOrderOperation
{
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => 400,
        'planned_start_at' => now()->startOfDay(),
        'planned_end_at' => now()->endOfDay(),
    ]);

    return ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $work_center->id,
        'setup_time_minutes' => 0,
        'cycle_time_minutes' => 2,
    ]);
}

it('warns when an operation starts on a work center materialised as overloaded', function (): void {
    Event::fake([CapacityOverloadDetected::class]);
    $operation = overloadedOperation();
    resolve(WorkCenterKpiMaterializer::class)->materialize($operation->workCenter, now());

    resolve(ProductionOrderOperationService::class)->start($operation);

    Event::assertDispatched(CapacityOverloadDetected::class, static fn (CapacityOverloadDetected $event): bool => $event->work_center_id === $operation->work_center_id
        && $event->production_order_operation_id === $operation->id
        && $event->capacity_load === 800.0
        && $event->available_minutes === 480.0);
});

it('does not warn when the work center is within capacity, and still starts the operation', function (): void {
    Event::fake([CapacityOverloadDetected::class]);
    $operation = overloadedOperation();
    $operation->productionOrder->update(['quantity_planned' => 10]);
    resolve(WorkCenterKpiMaterializer::class)->materialize($operation->workCenter, now());

    $started = resolve(ProductionOrderOperationService::class)->start($operation);

    expect($started->actual_start_at)->not->toBeNull();
    Event::assertNotDispatched(CapacityOverloadDetected::class);
});

it('does not warn, and does not recompute live, when nothing was materialised', function (): void {
    Event::fake([CapacityOverloadDetected::class]);
    $operation = overloadedOperation();

    resolve(ProductionOrderOperationService::class)->start($operation);

    Event::assertNotDispatched(CapacityOverloadDetected::class);
});

it('notifies recipients holding the configured role', function (): void {
    Notification::fake();
    config(['mes.notifications.capacity_overload.recipients.roles' => ['planner']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('planner', 'web'));
    $operation = overloadedOperation();

    new NotifyCapacityOverload()->handle(new CapacityOverloadDetected(
        company_id: $operation->workCenter->company_id,
        work_center_id: $operation->work_center_id,
        production_order_id: $operation->production_order_id,
        production_order_operation_id: $operation->id,
        capacity_load: 800.0,
        available_minutes: 480.0,
    ));

    Notification::assertSentTo($user, CapacityOverloadNotification::class);
});
