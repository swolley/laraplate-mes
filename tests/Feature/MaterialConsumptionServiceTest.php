<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Services\MaterialConsumptionService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

function stockReaderReturning(float $available): StockReader
{
    $reader = Mockery::mock(StockReader::class);
    $reader->shouldReceive('availableQuantity')->andReturn($available);

    return $reader;
}

it('records a manual consumption and a stock-out movement when stock suffices', function (): void {
    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $order = ProductionOrder::factory()->create(['company_id' => $company->id]);

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (StockMovementData $data): bool => $data->direction === 'out'
            && $data->item_id === $component->id
            && $data->quantity === 7);

    $consumption = new MaterialConsumptionService($recorder, stockReaderReturning(100.0))
        ->recordManual($order, $component->id, 7.0, 'pcs');

    expect($consumption->is_backflush)->toBeFalse()
        ->and($consumption->stock_shortage)->toBeFalse()
        ->and((float) $consumption->quantity_consumed)->toBe(7.0)
        ->and($consumption->warehouse_id)->toBe($order->warehouse_id)
        ->and(MaterialConsumption::query()->where('production_order_id', $order->id)->count())->toBe(1);
});

it('consumes what is available and flags the shortfall when stock is short', function (): void {
    Event::fake([MaterialShortageDetected::class]);

    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $order = ProductionOrder::factory()->create(['company_id' => $company->id]);

    // Needs 7, only 3 on hand: consume 3, short 4.
    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (StockMovementData $data): bool => $data->direction === 'out'
            && $data->item_id === $component->id
            && $data->quantity === 3);

    $consumption = new MaterialConsumptionService($recorder, stockReaderReturning(3.0))
        ->recordManual($order, $component->id, 7.0, 'pcs');

    expect($consumption->stock_shortage)->toBeTrue()
        ->and((float) $consumption->quantity_consumed)->toBe(3.0)
        ->and((float) $consumption->variance)->toBe(-4.0);

    Event::assertDispatched(
        MaterialShortageDetected::class,
        static fn (MaterialShortageDetected $event): bool => $event->item_id === $component->id
            && $event->required_quantity === 7.0
            && $event->available_quantity === 3.0
            && $event->is_backflush === false,
    );
});
