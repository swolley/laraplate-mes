<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Events\OperationCompleted;
use Modules\MES\Events\OperationSkipped;
use Modules\MES\Events\OperationStarted;
use Modules\MES\Events\ProductionOrderCancelled;
use Modules\MES\Events\ProductionOrderCompleted;
use Modules\MES\Events\ProductionOrderReleased;
use Modules\MES\Events\ProductionOrderStarted;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function lifecycleOrder(int $operations = 2): ProductionOrder
{
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    return ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => 5,
        'routing_snapshot' => [
            'id' => 1,
            'version' => 'v1',
            'operations' => collect(range(1, $operations))->map(static fn (int $n): array => [
                'routing_operation_id' => null,
                'work_center_id' => $work_center->id,
                'sequence' => $n * 10,
                'description' => "Step {$n}",
                'setup_time_minutes' => 10,
                'cycle_time_minutes' => 2,
                'is_parallel' => false,
            ])->all(),
        ],
    ]);
}

it('emits ProductionOrderReleased on release', function (): void {
    Event::fake([ProductionOrderReleased::class]);
    $order = lifecycleOrder();

    resolve(ProductionOrderService::class)->release($order);

    Event::assertDispatched(ProductionOrderReleased::class, static fn (ProductionOrderReleased $event): bool => $event->production_order_id === $order->id
        && $event->company_id === $order->company_id);
});

it('moves the order to in progress when its first operation starts, once', function (): void {
    $order = resolve(ProductionOrderService::class)->release(lifecycleOrder());
    Event::fake([ProductionOrderStarted::class, OperationStarted::class]);
    $operations = $order->operations()->orderBy('sequence')->get();
    $service = resolve(ProductionOrderOperationService::class);

    $service->start($operations[0]);
    $service->start($operations[1]);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and($order->fresh()->actual_start_at)->not->toBeNull();
    Event::assertDispatchedTimes(ProductionOrderStarted::class, 1);
    Event::assertDispatchedTimes(OperationStarted::class, 2);
});

it('emits operation completed and skipped events', function (): void {
    $order = resolve(ProductionOrderService::class)->release(lifecycleOrder());
    Event::fake([OperationCompleted::class, OperationSkipped::class]);
    [$first, $second] = $order->operations()->orderBy('sequence')->get()->all();
    $service = resolve(ProductionOrderOperationService::class);

    $service->start($first);
    $service->complete($first, 12.0);
    $service->skip($second);

    Event::assertDispatched(OperationCompleted::class, static fn (OperationCompleted $event): bool => $event->production_order_operation_id === $first->id
        && $event->production_order_id === $order->id);
    Event::assertDispatched(OperationSkipped::class, static fn (OperationSkipped $event): bool => $event->production_order_operation_id === $second->id);
});

it('emits ProductionOrderCompleted on completion', function (): void {
    $order = resolve(ProductionOrderService::class)->release(lifecycleOrder(1));
    Event::fake([ProductionOrderCompleted::class]);
    $operation = $order->operations()->firstOrFail();
    resolve(ProductionOrderOperationService::class)->start($operation);
    resolve(ProductionOrderOperationService::class)->complete($operation, 12.0);

    resolve(ProductionOrderService::class)->complete($order->fresh(), 5.0);

    Event::assertDispatched(ProductionOrderCompleted::class, static fn (ProductionOrderCompleted $event): bool => $event->production_order_id === $order->id
        && $event->quantity_produced === 5.0);
});

it('cancels an in-progress order, ending its running and pending operations', function (): void {
    $order = resolve(ProductionOrderService::class)->release(lifecycleOrder(3));
    [$done, $running, $pending] = $order->operations()->orderBy('sequence')->get()->all();
    $operations = resolve(ProductionOrderOperationService::class);
    $operations->start($done);
    $operations->complete($done, 12.0);
    $operations->start($running);
    Event::fake([ProductionOrderCancelled::class]);

    $cancelled = resolve(ProductionOrderService::class)->cancel($order->fresh());

    expect($cancelled->status)->toBe(ProductionOrderStatus::Cancelled)
        ->and($done->fresh()->status)->toBe(ProductionOrderOperationStatus::Completed)
        ->and($running->fresh()->status)->toBe(ProductionOrderOperationStatus::Skipped)
        ->and($pending->fresh()->status)->toBe(ProductionOrderOperationStatus::Skipped);
    Event::assertDispatched(ProductionOrderCancelled::class, static fn (ProductionOrderCancelled $event): bool => $event->production_order_id === $order->id);
});

it('still refuses to cancel a completed order', function (): void {
    $order = lifecycleOrder(0);
    $service = resolve(ProductionOrderService::class);
    $completed = $service->complete($service->release($order), 5.0);

    expect(fn () => $service->cancel($completed))->toThrow(DomainException::class);
});
