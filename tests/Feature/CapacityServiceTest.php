<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\CapacityService;
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
