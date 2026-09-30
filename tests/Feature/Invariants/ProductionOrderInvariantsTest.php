<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\CapacityService;
use Modules\MES\Services\LotTracingService;
use Modules\MES\Services\OeeCalculatorService;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

/*
 * Invariants of the production order domain, each checked over several inputs:
 * frozen snapshots, unique numbering, completion coherence with operations,
 * bounded OEE, non-negative capacity and symmetric lot traceability.
 *
 * State coherence is covered only as far as the service enforces it today
 * (no completion with an operation in progress); the rest of the order state
 * machine awaits a decision recorded in the MES plan.
 */

uses(RefreshDatabase::class);

/**
 * A draft order for an item with an active BOM (one backflushed component)
 * and an active routing of the given number of operations.
 */
function invariantDraftOrder(int $operations = 1): ProductionOrder
{
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    $bom = Bom::factory()->create(['company_id' => $company->id, 'item_id' => $finished->id, 'valid_from' => now()->subDay()->toDateString()]);
    BomLine::query()->create([
        'bom_id' => $bom->id,
        'item_id' => MesTestHelpers::makeItem($company->id)->id,
        'quantity' => 2,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    $routing = Routing::factory()->create(['company_id' => $company->id, 'item_id' => $finished->id, 'valid_from' => now()->subDay()->toDateString()]);
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    foreach (range(1, $operations) as $index) {
        RoutingOperation::query()->create([
            'routing_id' => $routing->id,
            'work_center_id' => $work_center->id,
            'sequence' => $index * 10,
            'description' => "Operation {$index}",
            'setup_time_minutes' => 5,
            'cycle_time_minutes' => 1,
        ]);
    }

    return resolve(ProductionOrderService::class)->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'quantity_planned' => 4,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $warehouse->id,
    ]);
}

it('keeps the frozen snapshots unchanged however the live bom and routing change', function (int $edits): void {
    $order = invariantDraftOrder();
    $bom_snapshot = $order->bom_snapshot;
    $routing_snapshot = $order->routing_snapshot;

    foreach (range(1, $edits) as $edit) {
        BomLine::query()->where('bom_id', $bom_snapshot['id'])->update(['quantity' => 2 + $edit]);
        RoutingOperation::query()->where('routing_id', $routing_snapshot['id'])->update(['cycle_time_minutes' => 1 + $edit]);
    }

    resolve(ProductionOrderService::class)->release($order);
    $order->refresh();

    expect($order->bom_snapshot)->toBe($bom_snapshot)
        ->and($order->routing_snapshot)->toBe($routing_snapshot);
})->with([1, 3, 5]);

it('allocates a distinct number to every order of a company', function (int $count): void {
    $first = invariantDraftOrder();
    $service = resolve(ProductionOrderService::class);

    $numbers = collect(range(2, $count))
        ->map(static fn (): string => $service->create([
            'company_id' => $first->company_id,
            'item_id' => $first->item_id,
            'quantity_planned' => 1,
            'uom' => 'pcs',
            'planned_start_at' => now(),
            'planned_end_at' => now()->addDay(),
            'warehouse_id' => $first->warehouse_id,
        ])->number)
        ->push($first->number);

    expect($numbers->filter()->unique())->toHaveCount($count);
})->with([2, 5, 10]);

it('never completes an order while any of its operations is in progress', function (int $operations, int $in_progress_index): void {
    $order = resolve(ProductionOrderService::class)->release(invariantDraftOrder($operations));
    $operation = $order->operations()->orderBy('sequence')->get()[$in_progress_index];
    resolve(ProductionOrderOperationService::class)->start($operation);

    expect(fn () => resolve(ProductionOrderService::class)->complete($order, 4.0))->toThrow(DomainException::class);

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Released)
        ->and($order->operations()->where('status', ProductionOrderOperationStatus::InProgress->value)->count())->toBe(1);
})->with([
    'single operation' => [1, 0],
    'first of three' => [3, 0],
    'last of three' => [3, 2],
]);

it('completes an order once no operation is in progress', function (int $operations): void {
    $order = resolve(ProductionOrderService::class)->release(invariantDraftOrder($operations));

    foreach ($order->operations as $operation) {
        resolve(ProductionOrderOperationService::class)->complete(
            resolve(ProductionOrderOperationService::class)->start($operation),
            10.0,
        );
    }

    expect(resolve(ProductionOrderService::class)->complete($order, 4.0)->status)->toBe(ProductionOrderStatus::Completed);
})->with([1, 3]);

it('keeps oee within [0, 1] for any factors', function (float $availability, float $performance, float $quality): void {
    $oee = resolve(OeeCalculatorService::class)->compose($availability, $performance, $quality);

    expect($oee)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
})->with([
    [0.0, 0.0, 0.0],
    [1.0, 1.0, 1.0],
    [0.9, 0.8, 0.95],
    [1.7, 2.5, 3.0],
    [-1.0, 0.5, 0.5],
    [0.5, -3.0, 9.0],
]);

it('keeps capacity load and available minutes non-negative', function (float $downtime_minutes): void {
    $order = resolve(ProductionOrderService::class)->release(invariantDraftOrder(2));
    $work_center_id = $order->operations()->value('work_center_id');

    Downtime::factory()->create([
        'company_id' => $order->company_id,
        'work_center_id' => $work_center_id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->startOfDay(),
        'ended_at' => now()->startOfDay()->addMinutes((int) $downtime_minutes),
    ]);

    $service = resolve(CapacityService::class);
    $from = now()->startOfDay();
    $to = now()->endOfDay();

    expect($service->getCapacityLoad($work_center_id, $from, $to))->toBeGreaterThanOrEqual(0.0)
        ->and($service->availableMinutes($work_center_id, $from, $to))->toBeGreaterThanOrEqual(0.0);
})->with([0.0, 120.0, 480.0, 1439.0]);

it('traces every lineage edge symmetrically forward and backward', function (int $depth): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $service = resolve(LotTracingService::class);

    $lots = collect(range(0, $depth))
        ->map(static fn (): LotNumber => LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]));

    foreach (range(1, $depth) as $index) {
        $service->recordLineage($lots[$index - 1], $lots[$index]);
    }

    foreach ($lots as $parent) {
        foreach ($service->forwardTrace($parent->id) as $descendant_id) {
            expect($service->backwardTrace($descendant_id))->toContain($parent->id);
        }
    }

    expect($service->forwardTrace($lots->first()->id))->toHaveCount($depth)
        ->and($service->backwardTrace($lots->last()->id))->toHaveCount($depth);
})->with([1, 2, 4]);
