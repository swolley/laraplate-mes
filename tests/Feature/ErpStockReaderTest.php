<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ERP\Services\Inventory\StockMovementService;
use Modules\MES\Contracts\StockReader;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('reads on-hand quantity from the ERP stock level', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    app(StockMovementService::class)->recordInbound(
        company_id: $company->id,
        item_id: $item->id,
        warehouse_id: $warehouse->id,
        quantity: 15,
        unit_cost: 2,
    );

    expect(resolve(StockReader::class)->availableQuantity($item->id, $warehouse->id, $company->id))
        ->toBe(15.0);
});

it('returns zero when no stock level exists', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);
    $warehouse = MesTestHelpers::makeWarehouse($company->id);

    expect(resolve(StockReader::class)->availableQuantity($item->id, $warehouse->id, $company->id))
        ->toBe(0.0);
});
