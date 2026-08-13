<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Jobs\BackflushMaterialsJob;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * @return array{operation: ProductionOrderOperation, component: int, manual: int}
 */
function backflushScenario(): array
{
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $component = MesTestHelpers::makeItem($company->id);
    $manual = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'warehouse_id' => $warehouse->id,
        'quantity_planned' => 10,
        'quantity_produced' => null,
        'bom_snapshot' => [
            'id' => 1,
            'version' => 'v1',
            'lines' => [
                [
                    'item_id' => $component->id,
                    'quantity' => 2.0,
                    'uom' => 'pcs',
                    'consumption_method' => ConsumptionMethod::Backflush->value,
                    'routing_operation_id' => 50,
                ],
                [
                    'item_id' => $manual->id,
                    'quantity' => 1.0,
                    'uom' => 'pcs',
                    'consumption_method' => ConsumptionMethod::Manual->value,
                    'routing_operation_id' => 50,
                ],
            ],
        ],
    ]);

    $operation = ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $work_center->id,
        'routing_operation_id' => 50,
        'sequence' => 10,
    ]);

    return ['operation' => $operation, 'component' => $component->id, 'manual' => $manual->id];
}

it('backflushes the matching line and records a stock-out movement', function (): void {
    $scenario = backflushScenario();
    $component_id = $scenario['component'];

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (StockMovementData $data): bool => $data->direction === 'out'
            && $data->item_id === $component_id
            && $data->quantity === 20); // 2 per unit * 10 planned

    (new BackflushMaterialsJob($scenario['operation']->id))->handle($recorder);

    $consumption = MaterialConsumption::query()->where('item_id', $component_id)->first();

    expect(MaterialConsumption::query()->count())->toBe(1)
        ->and($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_planned)->toBe(20.0)
        ->and($consumption->is_backflush)->toBeTrue();
});

it('does not consume manually-managed lines', function (): void {
    $scenario = backflushScenario();

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')->once();

    (new BackflushMaterialsJob($scenario['operation']->id))->handle($recorder);

    expect(MaterialConsumption::query()->where('item_id', $scenario['manual'])->exists())->toBeFalse();
});

it('is idempotent across repeated runs for the same operation', function (): void {
    $scenario = backflushScenario();

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record'); // any number of times

    $job = new BackflushMaterialsJob($scenario['operation']->id);
    $job->handle($recorder);
    $job->handle($recorder);

    expect(MaterialConsumption::query()->count())->toBe(1);
});
