<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Role;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Events\OperationTargetReached;
use Modules\MES\Listeners\NotifyOperationTargetReached;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Notifications\OperationTargetReachedNotification;
use Modules\MES\Services\OperationCountTally;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

function tallyOperation(float $planned = 100): ProductionOrderOperation
{
    $order = ProductionOrder::factory()->create(['quantity_planned' => $planned]);

    return ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'status' => ProductionOrderOperationStatus::InProgress->value]);
}

function countRow(ProductionOrderOperation $operation, string $time, float $good = 0, float $scrap = 0): MachineCount
{
    return MachineCount::factory()->create([
        'work_center_id' => $operation->work_center_id,
        'production_order_operation_id' => $operation->id,
        'ts' => "2026-10-05 {$time}",
        'good' => $good,
        'scrap' => $scrap,
    ]);
}

it('sums the attributed rows into the machine quantities, and again after a late row', function (): void {
    $operation = tallyOperation();
    countRow($operation, '08:00:00', good: 30);
    countRow($operation, '08:02:00', good: 20, scrap: 2);
    $tally = resolve(OperationCountTally::class);

    $tally->refresh($operation->id);
    $fresh = $operation->fresh();
    expect((float) $fresh->machine_good_quantity)->toBe(50.0)
        ->and((float) $fresh->machine_scrap_quantity)->toBe(2.0);

    countRow($operation, '08:01:00', good: 5, scrap: 1);
    $tally->refresh($operation->id);

    expect((float) $operation->fresh()->machine_good_quantity)->toBe(55.0)
        ->and((float) $operation->fresh()->machine_scrap_quantity)->toBe(3.0);
});

it('dispatches the target reached event once, never clears it, and never completes the operation', function (): void {
    Event::fake([OperationTargetReached::class]);
    $operation = tallyOperation(100);
    $tally = resolve(OperationCountTally::class);
    countRow($operation, '08:00:00', good: 90);
    $tally->refresh($operation->id);
    Event::assertNotDispatched(OperationTargetReached::class);

    countRow($operation, '08:01:00', good: 10);
    $tally->refresh($operation->id);
    $tally->refresh($operation->id);

    Event::assertDispatchedTimes(OperationTargetReached::class, 1);
    Event::assertDispatched(OperationTargetReached::class, static fn (OperationTargetReached $event): bool => $event->production_order_operation_id === $operation->id && $event->good === 100.0 && $event->planned === 100.0);
    $reached_at = $operation->fresh()->target_reached_at;
    expect($reached_at)->not->toBeNull()
        ->and($operation->fresh()->status)->toBe(ProductionOrderOperationStatus::InProgress);

    MachineCount::query()->where('good', 10)->delete();
    $tally->refresh($operation->id);
    countRow($operation, '08:02:00', good: 50);
    $tally->refresh($operation->id);

    expect($operation->fresh()->target_reached_at?->equalTo($reached_at))->toBeTrue();
    Event::assertDispatchedTimes(OperationTargetReached::class, 1);
});

it('notifies the recipients holding the configured role', function (): void {
    Notification::fake();
    config(['mes.notifications.operation_target.recipients.roles' => ['planner']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('planner', 'web'));
    $operation = tallyOperation(100);

    new NotifyOperationTargetReached()->handle(new OperationTargetReached(
        company_id: (int) $operation->productionOrder->company_id,
        production_order_id: $operation->production_order_id,
        production_order_operation_id: $operation->id,
        work_center_id: $operation->work_center_id,
        good: 100.0,
        planned: 100.0,
    ));

    Notification::assertSentTo($user, OperationTargetReachedNotification::class);
});

it('refreshes the operations of the rows the recorder writes', function (): void {
    $operation = tallyOperation(100);
    $device = Modules\MES\Models\MachineDevice::factory()->create(['work_center_id' => $operation->work_center_id]);
    $signal = Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'good', 'role' => Modules\MES\Enums\SignalRole::GoodCount->value, 'config' => ['mode' => 'delta']]);
    $sample = new Modules\MES\Machine\Data\ResolvedSample($device, $signal, new Modules\MES\Machine\Data\NormalizedSample('d', 'good', Carbon\CarbonImmutable::parse('2026-10-05 08:00:00', config()->string('app.timezone')), 12.0), $operation->id);

    resolve(Modules\MES\Listeners\PartsCountRecorder::class)->handle(new Modules\MES\Events\PartsCounted((int) $device->company_id, $device->id, (int) $device->work_center_id, [$sample]));

    expect((float) $operation->fresh()->machine_good_quantity)->toBe(12.0);
});
