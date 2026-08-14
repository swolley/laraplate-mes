<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Modules\MES\Filament\Resources\Downtimes\DowntimeResource;
use Modules\MES\Filament\Resources\NonConformances\NonConformanceResource;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Modules\MES\Filament\Resources\QualityChecks\QualityCheckResource;
use Modules\MES\Filament\Resources\QualityPlans\QualityPlanResource;
use Modules\MES\Filament\Resources\Routings\RoutingResource;
use Modules\MES\Filament\Resources\Shifts\ShiftResource;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\Routing;
use Modules\MES\Models\Shift;
use Modules\MES\Models\WorkCenter;

uses(RefreshDatabase::class);

/**
 * @return array<class-string, class-string>
 */
function mesResourceModels(): array
{
    return [
        WorkCenterResource::class => WorkCenter::class,
        BomResource::class => Bom::class,
        RoutingResource::class => Routing::class,
        ProductionOrderResource::class => ProductionOrder::class,
        QualityPlanResource::class => QualityPlan::class,
        QualityCheckResource::class => QualityCheck::class,
        NonConformanceResource::class => NonConformance::class,
        DowntimeResource::class => Downtime::class,
        ShiftResource::class => Shift::class,
    ];
}

it('binds each MES resource to its model and registers a list page', function (): void {
    foreach (mesResourceModels() as $resource => $model) {
        expect($resource::getModel())->toBe($model)
            ->and($resource::getPages())->toHaveKey('index');
    }
});

it('offers create only for directly-managed resources', function (): void {
    // Service-driven aggregates expose no bare create page.
    expect(ProductionOrderResource::getPages())->not->toHaveKey('create')
        ->and(NonConformanceResource::getPages())->not->toHaveKey('create')
        ->and(WorkCenterResource::getPages())->toHaveKey('create')
        ->and(ShiftResource::getPages())->toHaveKey('create');
});

it('configures every MES resource form and table without throwing', function (): void {
    $livewire = $this->createStub(HasTable::class);

    foreach (array_keys(mesResourceModels()) as $resource) {
        expect($resource::form(Schema::make()))->toBeInstanceOf(Schema::class);

        $table = Table::make($livewire);
        $table->query(fn () => $resource::getModel()::query());
        $resource::table($table);

        expect($table->getQuery())->not->toBeNull();
    }
});
