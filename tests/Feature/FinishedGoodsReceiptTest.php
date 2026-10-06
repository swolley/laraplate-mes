<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ERP\Casts\StockMovementDirection;
use Modules\ERP\Models\StockLevel;
use Modules\ERP\Models\StockMovement;
use Modules\ERP\Services\Inventory\StockMovementService;
use Modules\MES\Contracts\ProductionCostReader;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\MaterialConsumptionService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * A released order for a finished item, with a component stocked at 4.00 a unit.
 *
 * @return array{order: ProductionOrder, component_id: int}
 */
function finishedGoodsScenario(): array
{
    $company = MesTestHelpers::makeCompany();
    $warehouse = MesTestHelpers::makeWarehouse($company->id);
    $finished = MesTestHelpers::makeItem($company->id);
    $component = MesTestHelpers::makeItem($company->id);

    resolve(StockMovementService::class)->recordInbound($company->id, $component->id, $warehouse->id, 100, 4.0);

    $order = ProductionOrder::factory()->released()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'warehouse_id' => $warehouse->id,
        'quantity_planned' => 5,
    ]);

    return ['order' => $order, 'component_id' => $component->id];
}

function finishedStock(ProductionOrder $order): ?StockLevel
{
    return StockLevel::query()->withoutGlobalScopes()
        ->where('item_id', $order->item_id)
        ->where('warehouse_id', $order->warehouse_id)
        ->first();
}

it('receives the produced quantity into stock, valued at the material cost per unit', function (): void {
    $ctx = finishedGoodsScenario();
    resolve(MaterialConsumptionService::class)->recordManual($ctx['order'], $ctx['component_id'], 10.0);

    resolve(ProductionOrderService::class)->complete($ctx['order'], 5.0);

    $stock = finishedStock($ctx['order']);
    $receipt = StockMovement::query()->withoutGlobalScopes()
        ->where('item_id', $ctx['order']->item_id)
        ->where('direction', StockMovementDirection::In->value)
        ->sole();

    // 10 components at 4.00 = 40.00 over 5 finished units.
    expect((float) $stock->quantity)->toBe(5.0)
        ->and((float) $receipt->unit_cost)->toBe(8.0)
        ->and($receipt->source_id)->toBe($ctx['order']->id);
});

it('receives the finished goods at zero cost when nothing was consumed', function (): void {
    $ctx = finishedGoodsScenario();

    resolve(ProductionOrderService::class)->complete($ctx['order'], 3.0);

    expect((float) finishedStock($ctx['order'])->quantity)->toBe(3.0);
});

it('posts no receipt when nothing was produced', function (): void {
    $ctx = finishedGoodsScenario();

    resolve(ProductionOrderService::class)->complete($ctx['order'], 0.0);

    expect(finishedStock($ctx['order']))->toBeNull();
});

it('rolls the completion back when the receipt fails', function (): void {
    $ctx = finishedGoodsScenario();
    $ctx['order']->update(['warehouse_id' => MesTestHelpers::makeWarehouse(MesTestHelpers::makeCompany()->id)->id]);

    expect(fn () => resolve(ProductionOrderService::class)->complete($ctx['order'], 5.0))->toThrow(Illuminate\Validation\ValidationException::class);

    expect($ctx['order']->fresh()->status->value)->toBe('released');
});

it('reads the material cost of an order from the stock-outs posted against it', function (): void {
    $ctx = finishedGoodsScenario();
    $service = resolve(MaterialConsumptionService::class);
    $service->recordManual($ctx['order'], $ctx['component_id'], 10.0);
    $service->recordManual($ctx['order'], $ctx['component_id'], 5.0);

    expect(resolve(ProductionCostReader::class)->materialCost($ctx['order']->company_id, $ctx['order']->id))->toBe(60.0)
        ->and(resolve(ProductionCostReader::class)->materialCost($ctx['order']->company_id, $ctx['order']->id + 999))->toBe(0.0);
});
