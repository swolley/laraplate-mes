<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\MaterialConsumptionService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('records a manual consumption and a stock-out movement', function (): void {
    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $order = ProductionOrder::factory()->create(['company_id' => $company->id]);

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (StockMovementData $data): bool => $data->direction === 'out'
            && $data->item_id === $component->id
            && $data->quantity === 7);

    $consumption = new MaterialConsumptionService($recorder)
        ->recordManual($order, $component->id, 7.0, 'pcs');

    expect($consumption->is_backflush)->toBeFalse()
        ->and((float) $consumption->quantity_consumed)->toBe(7.0)
        ->and($consumption->warehouse_id)->toBe($order->warehouse_id)
        ->and(MaterialConsumption::query()->where('production_order_id', $order->id)->count())->toBe(1);
});
