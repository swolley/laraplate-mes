<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ERP\Casts\SalesOrderLineStatus;
use Modules\ERP\Casts\SalesOrderStatus;
use Modules\ERP\Models\Party;
use Modules\ERP\Models\SalesOrder;
use Modules\ERP\Models\SalesOrderLine;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Enums\ProductionPlanningSkipReason;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\SalesOrderProductionPlanner;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function makeManufacturedItem(int $company_id): int
{
    $finished = MesTestHelpers::makeItem($company_id);
    $component = MesTestHelpers::makeItem($company_id);

    $bom = Bom::factory()->create([
        'company_id' => $company_id,
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
        'company_id' => $company_id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    RoutingOperation::query()->create([
        'routing_id' => $routing->id,
        'work_center_id' => WorkCenter::factory()->create(['company_id' => $company_id])->id,
        'sequence' => 10,
        'description' => 'Assembly',
        'setup_time_minutes' => 15,
        'cycle_time_minutes' => 3,
    ]);

    return $finished->id;
}

function makeSalesOrderWithLine(int $company_id, ?int $item_id, float $qty = 5, float $qty_delivered = 0): SalesOrder
{
    $party = Party::query()->withoutGlobalScopes()->create([
        'company_id' => $company_id,
        'name' => fake()->company(),
        'is_customer' => true,
    ]);

    $order = SalesOrder::query()->withoutGlobalScopes()->create([
        'company_id' => $company_id,
        'party_id' => $party->id,
        'currency' => 'EUR',
        'status' => SalesOrderStatus::Draft,
    ]);

    SalesOrderLine::query()->withoutGlobalScopes()->create([
        'sales_order_id' => $order->id,
        'item_id' => $item_id,
        'name' => 'Line',
        'qty_ordered' => $qty,
        'qty_delivered' => $qty_delivered,
        'qty_invoiced' => 0,
        'status' => SalesOrderLineStatus::Open,
    ]);

    return $order->refresh();
}

function planner(): SalesOrderProductionPlanner
{
    return resolve(SalesOrderProductionPlanner::class);
}

it('creates a production order for a manufactured line', function (): void {
    $company = MesTestHelpers::makeCompany();
    $warehouse = MesTestHelpers::makeWarehouse($company->id);
    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id, 5);
    $line = $order->lines->first();

    $result = planner()->planForOrder($order);

    expect($result->created)->toHaveCount(1)
        ->and($result->skipped)->toBe([]);

    $po = $result->created[0];

    expect($po->company_id)->toBe($company->id)
        ->and($po->item_id)->toBe($item_id)
        ->and($po->warehouse_id)->toBe($warehouse->id)
        ->and((float) $po->quantity_planned)->toBe(5.0)
        ->and($po->uom)->toBe('pcs')
        ->and($po->sales_order_id)->toBe($order->id)
        ->and($po->sales_order_line_id)->toBe($line->id)
        ->and($po->status)->toBe(ProductionOrderStatus::Draft)
        ->and($po->bom_snapshot['lines'])->toHaveCount(1)
        ->and($po->planned_end_at->greaterThan($po->planned_start_at))->toBeTrue();
});

it('skips a line without an item', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $order = makeSalesOrderWithLine($company->id, null);

    $result = planner()->planForOrder($order);

    expect($result->created)->toBe([])
        ->and($result->skipped)->toBe([$order->lines->first()->id => ProductionPlanningSkipReason::NoItem]);
});

it('skips a line whose item is not manufactured', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $purchased = MesTestHelpers::makeItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $purchased->id);

    $result = planner()->planForOrder($order);

    expect($result->created)->toBe([])
        ->and($result->skipped[$order->lines->first()->id])->toBe(ProductionPlanningSkipReason::NotManufactured);
});

it('skips a line with nothing left to produce', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id, qty: 5, qty_delivered: 5);

    $result = planner()->planForOrder($order);

    expect($result->created)->toBe([])
        ->and($result->skipped[$order->lines->first()->id])->toBe(ProductionPlanningSkipReason::NothingToProduce);
});

it('plans each line at most once', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id);

    planner()->planForOrder($order);
    $second = planner()->planForOrder($order->refresh());

    expect($second->created)->toBe([])
        ->and($second->skipped[$order->lines->first()->id])->toBe(ProductionPlanningSkipReason::AlreadyPlanned)
        ->and(ProductionOrder::query()->where('sales_order_line_id', $order->lines->first()->id)->count())->toBe(1);
});

it('skips when the warehouse is ambiguous', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    MesTestHelpers::makeWarehouse($company->id);
    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id);

    $result = planner()->planForOrder($order);

    expect($result->created)->toBe([])
        ->and($result->skipped[$order->lines->first()->id])->toBe(ProductionPlanningSkipReason::WarehouseUnresolved);
});

it('uses the configured warehouse for the company when several exist', function (): void {
    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $target = MesTestHelpers::makeWarehouse($company->id);
    config(['mes.production.default_warehouse' => [$company->id => $target->id]]);

    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id);

    $result = planner()->planForOrder($order);

    expect($result->created)->toHaveCount(1)
        ->and($result->created[0]->warehouse_id)->toBe($target->id);
});

it('creates production orders end to end when a sales order is confirmed', function (): void {
    config(['mes.queue.connection' => 'sync']);

    $company = MesTestHelpers::makeCompany();
    MesTestHelpers::makeWarehouse($company->id);
    $item_id = makeManufacturedItem($company->id);
    $order = makeSalesOrderWithLine($company->id, $item_id);

    $order->update(['status' => SalesOrderStatus::Confirmed]);

    expect(ProductionOrder::query()->where('sales_order_id', $order->id)->count())->toBe(1);
});
