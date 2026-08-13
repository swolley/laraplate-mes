<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ERP\Casts\TracingType;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\LotNumber;
use Modules\MES\Services\LotTracingService;
use Modules\MES\Services\ProductionOrderService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('has symmetric forward and backward traces over one lineage edge', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $service = resolve(LotTracingService::class);

    $parent = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $child = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $service->recordLineage($parent, $child);

    expect($service->forwardTrace($parent->id))->toContain($child->id)
        ->and($service->backwardTrace($child->id))->toContain($parent->id);
});

it('walks a multi-level lineage chain in both directions', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $service = resolve(LotTracingService::class);

    $a = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $b = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $c = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $service->recordLineage($a, $b);
    $service->recordLineage($b, $c);

    expect($service->forwardTrace($a->id))->toEqualCanonicalizing([$b->id, $c->id])
        ->and($service->backwardTrace($c->id))->toEqualCanonicalizing([$a->id, $b->id]);
});

it('records a lineage edge only once', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $service = resolve(LotTracingService::class);

    $parent = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);
    $child = LotNumber::factory()->create(['company_id' => $company->id, 'item_id' => $item->id]);

    $service->recordLineage($parent, $child);
    $service->recordLineage($parent, $child);

    expect(Modules\MES\Models\LotLineage::query()->count())->toBe(1);
});

it('generates a lot code from the configured format', function (): void {
    config()->set('mes.lot_number_format', '{YEAR}{MONTH}{DAY}-{SEQ}');
    $company = MesTestHelpers::makeCompany();

    $code = resolve(LotTracingService::class)->generateLotCode($company->id);

    expect($code)->toMatch('/^\d{8}-\d{4}$/');
});

it('generates a lot on completion for a lot-traced item', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $item->update(['tracing_type' => TracingType::Lot]);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    $service = resolve(ProductionOrderService::class);
    $order = $service->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'quantity_planned' => 5,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $warehouse->id,
    ]);
    $service->complete($service->release($order), 5.0);

    $lot = LotNumber::query()->where('production_order_id', $order->id)->first();

    expect($lot)->not->toBeNull()
        ->and((float) $lot->quantity)->toBe(5.0)
        ->and($order->refresh()->status)->toBe(ProductionOrderStatus::Completed);
});

it('does not generate a lot for a non-traced item', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $item->update(['tracing_type' => TracingType::None]);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    $service = resolve(ProductionOrderService::class);
    $order = $service->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'quantity_planned' => 5,
        'uom' => 'pcs',
        'planned_start_at' => now(),
        'planned_end_at' => now()->addDay(),
        'warehouse_id' => $warehouse->id,
    ]);
    $service->complete($service->release($order), 5.0);

    expect(LotNumber::query()->where('production_order_id', $order->id)->exists())->toBeFalse();
});
