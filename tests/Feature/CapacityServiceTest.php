<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\CapacityService;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * @return array{work_center: WorkCenter, order: ProductionOrder}
 */
function scheduledOrder(float $quantity = 5): array
{
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'quantity_planned' => $quantity,
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
    ]);

    foreach ([10, 20] as $sequence) {
        ProductionOrderOperation::factory()->create([
            'production_order_id' => $order->id,
            'work_center_id' => $work_center->id,
            'sequence' => $sequence,
            'setup_time_minutes' => 10,
            'cycle_time_minutes' => 2,
        ]);
    }

    return ['work_center' => $work_center, 'order' => $order];
}

it('computes a non-negative capacity load in standard minutes', function (): void {
    $ctx = scheduledOrder(quantity: 5);

    // 2 operations, each setup 10 + cycle 2 * qty 5 = 20 => 40 minutes.
    $load = resolve(CapacityService::class)->getCapacityLoad(
        $ctx['work_center']->id,
        now()->subHour(),
        now()->addDays(2),
    );

    expect($load)->toBeGreaterThanOrEqual(0.0)->toBe(40.0);
});

it('returns zero load for a work center with no operations in the window', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $load = resolve(CapacityService::class)->getCapacityLoad($work_center->id, now()->subDay(), now()->addDay());

    expect($load)->toBe(0.0);
});

it('flags an overload against a small available budget', function (): void {
    $ctx = scheduledOrder(quantity: 5);

    $overloaded = resolve(CapacityService::class)->checkOverload(
        $ctx['work_center']->id,
        now()->subHour(),
        now()->addDays(2),
        available_minutes: 30.0,
    );

    expect($overloaded)->toBeTrue();
});

it('lists the company schedule within a window', function (): void {
    $ctx = scheduledOrder();

    $schedule = resolve(CapacityService::class)->getSchedule(
        $ctx['order']->company_id,
        now()->subDay(),
        now()->addDays(2),
    );

    expect($schedule)->toHaveCount(2);
});

it('reschedules an operation to another work center', function (): void {
    $ctx = scheduledOrder();
    $target = WorkCenter::factory()->create(['company_id' => $ctx['order']->company_id]);
    $operation = ProductionOrderOperation::query()->where('production_order_id', $ctx['order']->id)->first();

    $moved = resolve(CapacityService::class)->rescheduleOperation($operation, $target->id);

    expect($moved->work_center_id)->toBe($target->id);
});

it('subtracts the unplanned downtime overlapping the window from the available minutes', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $from = now()->startOfDay();
    $to = now()->endOfDay();

    // 60 minutes, fully inside the window.
    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => $from->copy()->addHours(8),
        'ended_at' => $from->copy()->addHours(9),
        'duration_minutes' => 60,
    ]);
    // Started the day before: only the 30 minutes after midnight count.
    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Setup->value,
        'started_at' => $from->copy()->subMinutes(90),
        'ended_at' => $from->copy()->addMinutes(30),
        'duration_minutes' => 120,
    ]);
    // Planned maintenance takes the work center out of service, so it reduces capacity (OEE availability does not count it as a loss).
    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::PlannedMaintenance->value,
        'started_at' => $from->copy()->addHours(10),
        'ended_at' => $from->copy()->addHours(12),
        'duration_minutes' => 120,
    ]);
    // Another work center does not count.
    Downtime::factory()->closed(200)->create(['company_id' => $company->id, 'cause' => DowntimeCause::Breakdown->value]);

    expect(resolve(CapacityService::class)->availableMinutes($work_center->id, $from, $to))->toBe(480.0 - 90.0 - 120.0);
});

it('never reports negative available minutes', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->startOfDay(),
        'ended_at' => now()->endOfDay(),
    ]);

    expect(resolve(CapacityService::class)->availableMinutes($work_center->id, now()->startOfDay(), now()->endOfDay()))->toBe(0.0);
});

it('flags an overload once downtime eats into the available minutes', function (): void {
    $ctx = scheduledOrder(quantity: 100);
    $from = now()->startOfDay();
    $to = now()->addDay()->endOfDay();
    $service = resolve(CapacityService::class);

    // Load: 2 * (10 + 2 * 100) = 420 minutes against 960 available over two days.
    expect($service->checkOverload($ctx['work_center']->id, $from, $to))->toBeFalse();

    Downtime::factory()->create([
        'company_id' => $ctx['order']->company_id,
        'work_center_id' => $ctx['work_center']->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => $from->copy()->addHour(),
        'ended_at' => $from->copy()->addHours(11),
    ]);

    expect($service->checkOverload($ctx['work_center']->id, $from, $to))->toBeTrue();
});

it('counts an open downtime up to now', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $this->travelTo(now()->startOfDay()->addHours(12));

    resolve(DowntimeService::class)->open($work_center, DowntimeCause::Breakdown);
    $this->travel(45)->minutes();

    expect(resolve(CapacityService::class)->availableMinutes($work_center->id, now()->startOfDay(), now()->endOfDay()))->toBe(480.0 - 45.0);
});
