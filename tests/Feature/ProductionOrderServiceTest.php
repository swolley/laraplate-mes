<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * @return array{company: Modules\ERP\Models\Company, item: Modules\ERP\Models\Item, warehouse: Modules\ERP\Models\Warehouse, bom: Bom}
 */
function makeProducibleItem(): array
{
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
        'quantity' => 3,
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
        'work_center_id' => WorkCenter::factory()->create(['company_id' => $company->id])->id,
        'sequence' => 10,
        'description' => 'Assembly',
        'setup_time_minutes' => 10,
        'cycle_time_minutes' => 2,
    ]);

    return ['company' => $company, 'item' => $finished, 'warehouse' => $warehouse, 'bom' => $bom];
}

/**
 * @param  array{company: Modules\ERP\Models\Company, item: Modules\ERP\Models\Item, warehouse: Modules\ERP\Models\Warehouse, bom: Bom}  $ctx
 */
function createOrder(array $ctx): ProductionOrder
{
    return resolve(ProductionOrderService::class)->create([
        'company_id' => $ctx['company']->id,
        'item_id' => $ctx['item']->id,
        'quantity_planned' => 5,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $ctx['warehouse']->id,
    ]);
}

it('freezes bom and routing snapshots on create', function (): void {
    $ctx = makeProducibleItem();

    $order = createOrder($ctx);

    expect($order->bom_snapshot)->toBeArray()->toHaveKey('lines')
        ->and($order->bom_snapshot['lines'])->toHaveCount(1)
        ->and($order->routing_snapshot)->toBeArray()->toHaveKey('operations')
        ->and($order->routing_snapshot['operations'])->toHaveCount(1)
        ->and($order->status)->toBe(ProductionOrderStatus::Draft);
});

it('keeps the bom snapshot immutable after the live bom changes', function (): void {
    $ctx = makeProducibleItem();

    $order = createOrder($ctx);
    $ctx['bom']->bomLines()->delete();

    $order->refresh();

    expect($order->bom_snapshot['lines'])->not->toBeEmpty();
});

it('allocates a non-empty unique number per order', function (): void {
    $ctx = makeProducibleItem();

    $first = createOrder($ctx);
    $second = createOrder($ctx);

    expect($first->number)->not->toBeEmpty()
        ->and($second->number)->not->toBeEmpty()
        ->and($first->number)->not->toBe($second->number);
});

it('releases a draft order', function (): void {
    $ctx = makeProducibleItem();
    $order = createOrder($ctx);

    $released = resolve(ProductionOrderService::class)->release($order);

    expect($released->status)->toBe(ProductionOrderStatus::Released);
});

it('refuses to release a non-draft order', function (): void {
    $order = ProductionOrder::factory()->released()->create();

    expect(fn () => resolve(ProductionOrderService::class)->release($order))
        ->toThrow(DomainException::class);
});

it('completes a released order and records produced quantity', function (): void {
    $ctx = makeProducibleItem();
    $order = resolve(ProductionOrderService::class)->release(createOrder($ctx));

    $completed = resolve(ProductionOrderService::class)->complete($order, 4.0);

    expect($completed->status)->toBe(ProductionOrderStatus::Completed)
        ->and((float) $completed->quantity_produced)->toBe(4.0)
        ->and($completed->actual_end_at)->not->toBeNull();
});

it('cancels a draft order but refuses a completed one', function (): void {
    $ctx = makeProducibleItem();
    $service = resolve(ProductionOrderService::class);

    $cancelled = $service->cancel(createOrder($ctx));
    expect($cancelled->status)->toBe(ProductionOrderStatus::Cancelled);

    $completed = $service->complete($service->release(createOrder($ctx)), 5.0);
    expect(fn () => $service->cancel($completed))->toThrow(DomainException::class);
});
