<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\ERP\Casts\TracingType;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Jobs\BackflushMaterialsJob;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('runs a full production cycle from order to finished lot and consumed materials', function (): void {
    Queue::fake();
    $recorder = Mockery::spy(StockMovementRecorder::class);
    app()->instance(StockMovementRecorder::class, $recorder);

    // --- Master data: a lot-traced finished good with a backflush component ---
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $finished->update(['tracing_type' => TracingType::Lot]);
    $component = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $bom = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    BomLine::query()->create([
        'bom_id' => $bom->id,
        'item_id' => $component->id,
        'quantity' => 2,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    $routing = Routing::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => $work_center->id,
        'sequence' => 10,
        'description' => 'Assembly',
        'setup_time_minutes' => 10,
        'cycle_time_minutes' => 2,
    ]);

    // --- Create + release: snapshots frozen, operations materialised ---
    $order_service = resolve(ProductionOrderService::class);
    $order = $order_service->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'quantity_planned' => 10,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $warehouse->id,
    ]);

    expect($order->bom_snapshot['lines'])->toHaveCount(1)
        ->and($order->routing_snapshot['operations'])->toHaveCount(1);

    $order_service->release($order);

    $operation = ProductionOrderOperation::query()->where('production_order_id', $order->id)->firstOrFail();
    expect($operation->status)->toBe(ProductionOrderOperationStatus::Planned);

    // --- Execute the operation, then backflush its components ---
    $operation_service = resolve(ProductionOrderOperationService::class);
    $operation_service->complete($operation_service->start($operation), 20.0);

    Queue::assertPushed(BackflushMaterialsJob::class);
    new BackflushMaterialsJob($operation->id)->handle($recorder);

    $consumption = MaterialConsumption::query()->where('item_id', $component->id)->first();
    expect($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_planned)->toBe(20.0); // 2 per unit * 10 planned
    $recorder->shouldHaveReceived('record');

    // --- Complete the order: finished lot generated ---
    $completed = $order_service->complete($order->refresh(), 10.0);

    $lot = LotNumber::query()->where('production_order_id', $order->id)->first();

    expect($completed->status)->toBe(ProductionOrderStatus::Completed)
        ->and((float) $completed->quantity_produced)->toBe(10.0)
        ->and($operation->refresh()->status)->toBe(ProductionOrderOperationStatus::Completed)
        ->and($lot)->not->toBeNull()
        ->and((float) $lot->quantity)->toBe(10.0);
});

it('keeps the bom snapshot immutable regardless of later bom edits', function (int $edits): void {
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $component = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    $bom = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    BomLine::query()->create([
        'bom_id' => $bom->id,
        'item_id' => $component->id,
        'quantity' => 5,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    $order = resolve(ProductionOrderService::class)->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'quantity_planned' => 3,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $warehouse->id,
    ]);
    $frozen = $order->bom_snapshot;

    for ($i = 0; $i < $edits; $i++) {
        BomLine::query()->create([
            'bom_id' => $bom->id,
            'item_id' => MesTestHelpers::makeItem($company->id)->id,
            'quantity' => $i + 1,
            'uom' => 'pcs',
            'consumption_method' => ConsumptionMethod::Manual->value,
            'sort_order' => $i + 1,
        ]);
    }

    expect($order->refresh()->bom_snapshot)->toBe($frozen);
})->with([1, 3, 5]);
