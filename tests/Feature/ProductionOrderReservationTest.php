<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\ERP\Enums\StockReservationState;
use Modules\ERP\Models\StockReservation;
use Modules\ERP\Services\Inventory\StockMovementService;
use Modules\ERP\Services\Inventory\StockReservationService;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Events\MaterialShortageDetected;
use Modules\MES\Jobs\BackflushMaterialsJob;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * The opaque reservation source alias MES uses for a production order's BOM
 * material line. Pinned as a literal here so the stored contract cannot drift.
 */
const RESERVATION_SOURCE = 'mes.production_order_material';

const RESERVATION_LINE_ID = 7001;

/**
 * Build a draft production order with a single backflushed component line and
 * optionally seed on-hand stock for that component in its warehouse.
 *
 * @return array{company: Modules\ERP\Models\Company, component: Modules\ERP\Models\Item, warehouse: Modules\ERP\Models\Warehouse, order: ProductionOrder, line_id: int}
 */
function reservableOrder(int $on_hand, float $per_unit = 2.0, float $planned = 10.0): array
{
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $component = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    if ($on_hand > 0) {
        app(StockMovementService::class)->recordInbound(
            company_id: $company->id,
            item_id: $component->id,
            warehouse_id: $warehouse->id,
            quantity: $on_hand,
            unit_cost: 1,
        );
    }

    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'warehouse_id' => $warehouse->id,
        'quantity_planned' => $planned,
        'quantity_produced' => null,
        'status' => ProductionOrderStatus::Draft->value,
        'bom_snapshot' => [
            'id' => 1,
            'version' => 'v1',
            'lines' => [
                [
                    // Distinct template id, to prove the code keys on material_line_id.
                    'bom_line_id' => 55,
                    'material_line_id' => RESERVATION_LINE_ID,
                    'item_id' => $component->id,
                    'quantity' => $per_unit,
                    'uom' => 'pcs',
                    'consumption_method' => ConsumptionMethod::Backflush->value,
                    'routing_operation_id' => 50,
                ],
            ],
        ],
        'routing_snapshot' => ['id' => null, 'version' => null, 'operations' => []],
    ]);

    return [
        'company' => $company,
        'component' => $component,
        'warehouse' => $warehouse,
        'order' => $order,
        'line_id' => RESERVATION_LINE_ID,
    ];
}

function backflushOperation(ProductionOrder $order): ProductionOrderOperation
{
    $work_center = WorkCenter::factory()->create(['company_id' => $order->company_id]);

    return ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $work_center->id,
        'routing_operation_id' => 50,
        'sequence' => 10,
    ]);
}

function lineReservedQuantity(int $line_id): string
{
    return resolve(StockReservationService::class)->reservedQuantity(RESERVATION_SOURCE, $line_id);
}

/**
 * A finished item with one backflushed component line in its active BOM, plus
 * seeded component stock, so orders can be built from it through the service.
 *
 * @return array{company: Modules\ERP\Models\Company, finished: Modules\ERP\Models\Item, component: Modules\ERP\Models\Item, warehouse: Modules\ERP\Models\Warehouse}
 */
function sharedBomItem(float $per_unit, int $on_hand): array
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
        'quantity' => $per_unit,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    if ($on_hand > 0) {
        app(StockMovementService::class)->recordInbound(
            company_id: $company->id,
            item_id: $component->id,
            warehouse_id: $warehouse->id,
            quantity: $on_hand,
            unit_cost: 1,
        );
    }

    return ['company' => $company, 'finished' => $finished, 'component' => $component, 'warehouse' => $warehouse];
}

/**
 * @param  array{company: Modules\ERP\Models\Company, finished: Modules\ERP\Models\Item, component: Modules\ERP\Models\Item, warehouse: Modules\ERP\Models\Warehouse}  $ctx
 */
function orderFromSharedBom(array $ctx, float $planned): ProductionOrder
{
    return resolve(ProductionOrderService::class)->create([
        'company_id' => $ctx['company']->id,
        'item_id' => $ctx['finished']->id,
        'quantity_planned' => $planned,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $ctx['warehouse']->id,
    ]);
}

function materialLineId(ProductionOrder $order): int
{
    return (int) $order->refresh()->bom_snapshot['lines'][0]['material_line_id'];
}

function readerAvailable(int $item_id, int $warehouse_id, int $company_id): float
{
    return resolve(StockReader::class)->availableQuantity($item_id, $warehouse_id, $company_id);
}

it('reserves the BOM components when a production order is released and drops warehouse availability', function (): void {
    $ctx = reservableOrder(on_hand: 100);

    expect(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(100.0);

    resolve(ProductionOrderService::class)->release($ctx['order']);

    $reservation = StockReservation::query()
        ->where('source_type', RESERVATION_SOURCE)
        ->where('source_id', $ctx['line_id'])
        ->first();

    expect($reservation)->not->toBeNull()
        ->and($reservation->state)->toBe(StockReservationState::Hard)
        ->and($reservation->item_id)->toBe($ctx['component']->id)
        ->and($reservation->warehouse_id)->toBe($ctx['warehouse']->id)
        ->and((float) $reservation->quantity)->toBe(20.0)
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(80.0);
});

it('does not subtract a company-wide null-warehouse reservation from the per-warehouse availability', function (): void {
    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    app(StockMovementService::class)->recordInbound(
        company_id: $company->id,
        item_id: $component->id,
        warehouse_id: $warehouse->id,
        quantity: 40,
        unit_cost: 1,
    );

    resolve(StockReservationService::class)->reserve(
        $company->id,
        $component->id,
        '10',
        StockReservationState::Hard,
        'erp.some_company_wide_hold',
        999,
        null,
    );

    // The null-warehouse hold is a documented v1 limitation: it is not pinned to
    // the warehouse, so the per-warehouse reader does not subtract it.
    expect(readerAvailable($component->id, $warehouse->id, $company->id))->toBe(40.0);
});

it('releases the component reservations when a production order is cancelled', function (): void {
    $ctx = reservableOrder(on_hand: 100);

    $service = resolve(ProductionOrderService::class);
    $service->release($ctx['order']);

    expect((float) lineReservedQuantity($ctx['line_id']))->toBe(20.0)
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(80.0);

    $service->cancel($ctx['order']->refresh());

    expect((float) lineReservedQuantity($ctx['line_id']))->toBe(0.0)
        ->and(StockReservation::query()->where('source_type', RESERVATION_SOURCE)->active()->count())->toBe(0)
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(100.0);
});

it('consumes the reservation at backflush and never double-counts availability', function (): void {
    $ctx = reservableOrder(on_hand: 100);

    resolve(ProductionOrderService::class)->release($ctx['order']);
    $operation = backflushOperation($ctx['order']->refresh());

    (new BackflushMaterialsJob($operation->id))->handle(
        resolve(StockMovementRecorder::class),
        resolve(StockReader::class),
    );

    $consumption = MaterialConsumption::query()->where('item_id', $ctx['component']->id)->first();

    expect($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_consumed)->toBe(20.0)
        ->and($consumption->stock_shortage)->toBeFalse()
        ->and((float) lineReservedQuantity($ctx['line_id']))->toBe(0.0)
        ->and(StockReservation::query()
            ->where('source_type', RESERVATION_SOURCE)
            ->where('source_id', $ctx['line_id'])
            ->where('state', StockReservationState::Consumed->value)
            ->exists())->toBeTrue()
        // on-hand dropped 20 (100 -> 80), the hard reservation is now consumed: no double count.
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(80.0);
});

it('consumes only the hard-reserved part of the line, never more', function (): void {
    // Only 5 on hand at release, so the best-effort reserve pins just 5.
    $ctx = reservableOrder(on_hand: 5);

    resolve(ProductionOrderService::class)->release($ctx['order']);

    expect((float) lineReservedQuantity($ctx['line_id']))->toBe(5.0);

    // More stock arrives after the order was released; it is not reserved.
    app(StockMovementService::class)->recordInbound(
        company_id: $ctx['company']->id,
        item_id: $ctx['component']->id,
        warehouse_id: $ctx['warehouse']->id,
        quantity: 95,
        unit_cost: 1,
    );

    $operation = backflushOperation($ctx['order']->refresh());

    (new BackflushMaterialsJob($operation->id))->handle(
        resolve(StockMovementRecorder::class),
        resolve(StockReader::class),
    );

    $consumption = MaterialConsumption::query()->where('item_id', $ctx['component']->id)->first();

    // The job consumes the full planned 20 physically, but closes only the 5 reserved.
    expect((float) $consumption->quantity_consumed)->toBe(20.0)
        ->and($consumption->stock_shortage)->toBeFalse()
        ->and((float) lineReservedQuantity($ctx['line_id']))->toBe(0.0)
        ->and(StockReservation::query()
            ->where('source_type', RESERVATION_SOURCE)
            ->where('source_id', $ctx['line_id'])
            ->where('state', StockReservationState::Consumed->value)
            ->sum('quantity'))->toEqual('5.0000');
});

it('keeps the partial-consume and shortage behaviour when physical stock is short', function (): void {
    Event::fake([MaterialShortageDetected::class]);

    // Short stock, and no reservation for the line (never released): the additive
    // reservation-consume must be a no-op and the existing shortage path intact.
    $ctx = reservableOrder(on_hand: 5);
    $operation = backflushOperation($ctx['order']);

    $recorder = Mockery::mock(StockMovementRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (StockMovementData $data): bool => $data->direction === 'out'
            && $data->item_id === $ctx['component']->id
            && $data->quantity === 5);

    (new BackflushMaterialsJob($operation->id))->handle($recorder, resolve(StockReader::class));

    $consumption = MaterialConsumption::query()->where('item_id', $ctx['component']->id)->first();

    expect($consumption)->not->toBeNull()
        ->and((float) $consumption->quantity_consumed)->toBe(5.0)
        ->and((float) $consumption->variance)->toBe(-15.0)
        ->and($consumption->stock_shortage)->toBeTrue()
        ->and((float) lineReservedQuantity($ctx['line_id']))->toBe(0.0);

    Event::assertDispatched(
        MaterialShortageDetected::class,
        static fn (MaterialShortageDetected $event): bool => $event->item_id === $ctx['component']->id
            && $event->required_quantity === 20.0
            && $event->available_quantity === 5.0
            && $event->is_backflush === true,
    );
});

it('reserves independently and cancels in isolation for two orders built from the same BOM', function (): void {
    // One shared finished item / active BOM: both orders resolve the SAME template
    // bom_line_id, so pooling would be visible here.
    $ctx = sharedBomItem(per_unit: 2.0, on_hand: 1000);

    $service = resolve(ProductionOrderService::class);
    $order_a = orderFromSharedBom($ctx, 10.0);
    $order_b = orderFromSharedBom($ctx, 5.0);

    $service->release($order_a);
    $service->release($order_b);

    $line_a = materialLineId($order_a);
    $line_b = materialLineId($order_b);

    // Per-order material line ids, so the holds do not pool under a shared id.
    expect($line_a)->not->toBe($line_b)
        ->and((float) lineReservedQuantity($line_a))->toBe(20.0)
        ->and((float) lineReservedQuantity($line_b))->toBe(10.0);

    $service->cancel($order_a->refresh());

    // Cancelling A frees only A's holds; B's reservation is untouched.
    expect((float) lineReservedQuantity($line_a))->toBe(0.0)
        ->and((float) lineReservedQuantity($line_b))->toBe(10.0);
});

it('consumes only its own reservation at backflush when two orders share a BOM', function (): void {
    $ctx = sharedBomItem(per_unit: 2.0, on_hand: 1000);

    $service = resolve(ProductionOrderService::class);
    $order_a = orderFromSharedBom($ctx, 10.0);
    $order_b = orderFromSharedBom($ctx, 5.0);

    $service->release($order_a);
    $service->release($order_b);

    $line_a = materialLineId($order_a);
    $line_b = materialLineId($order_b);

    $operation = backflushOperation($order_a->refresh());

    (new BackflushMaterialsJob($operation->id))->handle(
        resolve(StockMovementRecorder::class),
        resolve(StockReader::class),
    );

    // A's reservation is closed; B's is left intact (no oldest-first cross-eating).
    expect((float) lineReservedQuantity($line_a))->toBe(0.0)
        ->and((float) lineReservedQuantity($line_b))->toBe(10.0);
});

it('consumes the order own reserved stock at backflush without a false shortage', function (): void {
    Event::fake([MaterialShortageDetected::class]);

    // on_hand 20, requirement 2 * 10 = 20: the order reserves ALL of it at release.
    // The reader then shows 0 available; the backflush must still consume its own 20.
    $ctx = reservableOrder(on_hand: 20);
    resolve(ProductionOrderService::class)->release($ctx['order']);

    expect((float) lineReservedQuantity($ctx['line_id']))->toBe(20.0)
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(0.0);

    $operation = backflushOperation($ctx['order']->refresh());

    (new BackflushMaterialsJob($operation->id))->handle(
        resolve(StockMovementRecorder::class),
        resolve(StockReader::class),
    );

    $consumption = MaterialConsumption::query()->where('item_id', $ctx['component']->id)->first();

    expect((float) $consumption->quantity_consumed)->toBe(20.0)
        ->and($consumption->stock_shortage)->toBeFalse()
        ->and((float) lineReservedQuantity($ctx['line_id']))->toBe(0.0);

    Event::assertNotDispatched(MaterialShortageDetected::class);
});

it('releases leftover component holds when the order completes', function (): void {
    $ctx = sharedBomItem(per_unit: 2.0, on_hand: 100);

    $service = resolve(ProductionOrderService::class);
    $order = orderFromSharedBom($ctx, 10.0);
    $service->release($order);

    $line = materialLineId($order);
    expect((float) lineReservedQuantity($line))->toBe(20.0);

    // Complete without backflushing: the 20 held would otherwise stay hard forever
    // on an order that can no longer be cancelled.
    $service->complete($order->refresh(), 10.0);

    expect((float) lineReservedQuantity($line))->toBe(0.0)
        ->and(readerAvailable($ctx['component']->id, $ctx['warehouse']->id, $ctx['company']->id))->toBe(100.0);
});

it('leaves the order released and logs when a component reservation fails', function (): void {
    Log::spy();

    $company = MesTestHelpers::makeCompany();
    $component = MesTestHelpers::makeItem($company->id);
    $stock_warehouse = MesTestHelpers::makeWarehouse($company->id);
    $foreign_warehouse = MesTestHelpers::makeWarehouse(MesTestHelpers::makeCompany()->id);

    app(StockMovementService::class)->recordInbound(
        company_id: $company->id,
        item_id: $component->id,
        warehouse_id: $stock_warehouse->id,
        quantity: 100,
        unit_cost: 1,
    );

    // The order points at a warehouse that is not the company's: the stock exists
    // (reserve is reached) but reserve() raises a ValidationException for the line.
    $order = ProductionOrder::factory()->create([
        'company_id' => $company->id,
        'warehouse_id' => $foreign_warehouse->id,
        'quantity_planned' => 10,
        'quantity_produced' => null,
        'status' => ProductionOrderStatus::Draft->value,
        'bom_snapshot' => [
            'id' => 1,
            'version' => 'v1',
            'lines' => [
                [
                    'bom_line_id' => 55,
                    'material_line_id' => 8001,
                    'item_id' => $component->id,
                    'quantity' => 2.0,
                    'uom' => 'pcs',
                    'consumption_method' => ConsumptionMethod::Backflush->value,
                    'routing_operation_id' => null,
                ],
            ],
        ],
        'routing_snapshot' => ['id' => null, 'version' => null, 'operations' => []],
    ]);

    $released = resolve(ProductionOrderService::class)->release($order);

    expect($released->status)->toBe(ProductionOrderStatus::Released)
        ->and(StockReservation::query()
            ->where('source_type', RESERVATION_SOURCE)
            ->where('source_id', 8001)
            ->count())->toBe(0);

    Log::shouldHaveReceived('warning');
});
