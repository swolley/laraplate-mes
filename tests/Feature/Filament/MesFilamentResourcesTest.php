<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Modules\MES\Filament\Resources\Routings\RoutingResource;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Modules\MES\Models\Bom;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;
use Modules\MES\Models\WorkCenter;

uses(RefreshDatabase::class);

it('binds each MES resource to its model', function (): void {
    expect(WorkCenterResource::getModel())->toBe(WorkCenter::class)
        ->and(BomResource::getModel())->toBe(Bom::class)
        ->and(RoutingResource::getModel())->toBe(Routing::class)
        ->and(ProductionOrderResource::getModel())->toBe(ProductionOrder::class);
});

it('registers list pages for every MES resource', function (): void {
    foreach ([WorkCenterResource::class, BomResource::class, RoutingResource::class, ProductionOrderResource::class] as $resource) {
        expect($resource::getPages())->toHaveKey('index');
    }

    // Production orders are service-driven: no bare create page.
    expect(ProductionOrderResource::getPages())->not->toHaveKey('create')
        ->and(WorkCenterResource::getPages())->toHaveKey('create');
});

it('configures MES resource forms and tables without throwing', function (): void {
    $livewire = $this->createStub(HasTable::class);

    foreach ([WorkCenterResource::class, BomResource::class, RoutingResource::class, ProductionOrderResource::class] as $resource) {
        expect($resource::form(Schema::make()))->toBeInstanceOf(Schema::class);

        $table = Table::make($livewire);
        $table->query(fn () => $resource::getModel()::query());
        $resource::table($table);

        expect($table->getQuery())->not->toBeNull();
    }
});
