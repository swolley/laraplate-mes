<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;
use Modules\MES\Services\CapacityService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Operations are created planned, with 10 + 2 x 5 = 20 standard minutes each.
 *
 * @param  list<array{wc?: WorkCenter, parallel?: bool, status?: ProductionOrderOperationStatus}>  $specs
 * @return array{order: ProductionOrder, operations: list<ProductionOrderOperation>}
 */
function schedulableOrder(array $specs, string $start = '2026-10-05 08:00'): array
{
    $company = MesTestHelpers::makeCompany();
    $default = WorkCenter::factory()->create(['company_id' => $company->id]);
    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => 5,
        'planned_start_at' => $start,
        'planned_end_at' => Carbon::parse($start)->addDays(3),
    ]);
    $operations = [];

    foreach ($specs as $index => $spec) {
        $operations[] = ProductionOrderOperation::factory()->create([
            'production_order_id' => $order->id,
            'work_center_id' => ($spec['wc'] ?? $default)->id,
            'sequence' => ($index + 1) * 10,
            'setup_time_minutes' => 10,
            'cycle_time_minutes' => 2,
            'is_parallel' => $spec['parallel'] ?? false,
            'status' => ($spec['status'] ?? ProductionOrderOperationStatus::Planned)->value,
        ]);
    }

    return ['order' => $order, 'operations' => $operations];
}

function weekdayWorkCenter(): WorkCenter
{
    $work_center = WorkCenter::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);

    foreach (range(0, 4) as $day) {
        WorkCenterCalendar::query()->create(['work_center_id' => $work_center->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
    }

    return $work_center;
}

it('plans the operations one after the other from the order start', function (): void {
    $ctx = schedulableOrder([[], []]);

    resolve(CapacityService::class)->scheduleOperations($ctx['order']);

    [$first, $second] = array_map(static fn (ProductionOrderOperation $operation): ProductionOrderOperation => $operation->fresh(), $ctx['operations']);
    expect($first->planned_start_at->toDateTimeString())->toBe('2026-10-05 08:00:00')
        ->and($first->planned_end_at->toDateTimeString())->toBe('2026-10-05 08:20:00')
        ->and($second->planned_start_at->toDateTimeString())->toBe('2026-10-05 08:20:00')
        ->and($second->planned_end_at->toDateTimeString())->toBe('2026-10-05 08:40:00');
});

it('starts a parallel operation together with the previous one', function (): void {
    $ctx = schedulableOrder([[], ['parallel' => true]]);

    resolve(CapacityService::class)->scheduleOperations($ctx['order']);

    expect($ctx['operations'][1]->fresh()->planned_start_at->toDateTimeString())->toBe('2026-10-05 08:00:00');
});

it('waits for the work center calendar', function (): void {
    // Saturday: the weekday calendar only opens again on Monday at 09:00.
    $ctx = schedulableOrder([['wc' => weekdayWorkCenter()]], '2026-10-10 10:00');

    resolve(CapacityService::class)->scheduleOperations($ctx['order']);

    expect($ctx['operations'][0]->fresh()->planned_start_at->toDateTimeString())->toBe('2026-10-12 09:00:00');
});

it('plans the operations when the order is released', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => 5,
        'planned_start_at' => '2026-10-05 08:00',
        'routing_snapshot' => ['id' => 1, 'version' => 'v1', 'operations' => [[
            'routing_operation_id' => null,
            'work_center_id' => $work_center->id,
            'sequence' => 10,
            'description' => 'Cut',
            'setup_time_minutes' => 10,
            'cycle_time_minutes' => 2,
            'is_parallel' => false,
        ]]],
    ]);

    resolve(ProductionOrderService::class)->release($order);

    $operation = $order->operations()->firstOrFail();
    expect($operation->planned_start_at->toDateTimeString())->toBe('2026-10-05 08:00:00')
        ->and($operation->planned_end_at->toDateTimeString())->toBe('2026-10-05 08:20:00');
});

it('estimates the completion from now over the operations still to do', function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    $ctx = schedulableOrder([
        ['status' => ProductionOrderOperationStatus::Completed],
        [],
        [],
    ]);

    // Two planned operations of 20 minutes each, from the current time.
    expect(resolve(CapacityService::class)->estimateCompletionDate($ctx['order'])->toDateTimeString())->toBe('2026-10-05 10:40:00');
});

it('estimates from the planned start while the order has not started yet, and falls back to the planned end without operations', function (): void {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $ctx = schedulableOrder([[]], '2026-10-05 08:00');
    $empty = ProductionOrder::factory()->create(['planned_end_at' => '2026-10-20 12:00']);
    $service = resolve(CapacityService::class);

    expect($service->estimateCompletionDate($ctx['order'])->toDateTimeString())->toBe('2026-10-05 08:20:00')
        ->and($service->estimateCompletionDate($empty)->toDateTimeString())->toBe('2026-10-20 12:00:00');
});

it('moves an operation to another work center and plans it from the given date', function (): void {
    $ctx = schedulableOrder([[]]);
    $target = weekdayWorkCenter();

    $moved = resolve(CapacityService::class)->rescheduleOperation($ctx['operations'][0], $target->id, Carbon::parse('2026-10-10 10:00'));

    expect($moved->work_center_id)->toBe($target->id)
        ->and($moved->planned_start_at->toDateTimeString())->toBe('2026-10-12 09:00:00')
        ->and($moved->planned_end_at->toDateTimeString())->toBe('2026-10-12 09:20:00');
});

it('still moves an operation without a date', function (): void {
    $ctx = schedulableOrder([[]]);
    $target = weekdayWorkCenter();

    $moved = resolve(CapacityService::class)->rescheduleOperation($ctx['operations'][0], $target->id);

    expect($moved->work_center_id)->toBe($target->id);
});

it('reads the calendar for the available minutes', function (): void {
    $work_center = weekdayWorkCenter();
    $service = resolve(CapacityService::class);

    expect($service->availableMinutes($work_center->id, Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59')))->toBe(480.0)
        ->and($service->availableMinutes($work_center->id, Carbon::parse('2026-10-10 00:00'), Carbon::parse('2026-10-11 23:59')))->toBe(0.0);

    Downtime::factory()->create([
        'company_id' => $work_center->company_id,
        'work_center_id' => $work_center->id,
        'started_at' => '2026-10-05 09:00',
        'ended_at' => '2026-10-05 10:00',
    ]);

    expect($service->availableMinutes($work_center->id, Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59')))->toBe(420.0);
});

it('counts a dated operation in proportion to its overlap with the window', function (): void {
    $ctx = schedulableOrder([[]]);
    $operation = $ctx['operations'][0];
    $operation->update(['planned_start_at' => '2026-10-05 12:00', 'planned_end_at' => '2026-10-05 14:00']);
    $service = resolve(CapacityService::class);

    // 20 standard minutes spread over two hours: a one-hour window holds half of them.
    expect($service->getCapacityLoad($operation->work_center_id, Carbon::parse('2026-10-05 12:00'), Carbon::parse('2026-10-05 13:00')))->toBe(10.0)
        ->and($service->getCapacityLoad($operation->work_center_id, Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59')))->toBe(20.0)
        ->and($service->getCapacityLoad($operation->work_center_id, Carbon::parse('2026-10-06 00:00'), Carbon::parse('2026-10-06 23:59')))->toBe(0.0);
});
