<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Modules\MES\Filament\Widgets\ProductionDashboardWidget;

it('registers the MES Filament surfaces on the admin panel', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin('mes'))->not->toBeNull()
        ->and($panel->getResources())->toContain(ProductionOrderResource::class, WorkCenterResource::class)
        ->and($panel->getWidgets())->toContain(ProductionDashboardWidget::class);
});

it('registers its own navigation group with the module icon', function (): void {
    $group = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->first(static fn (NavigationGroup $group): bool => $group->getLabel() === 'MES');

    expect($group)->not->toBeNull()
        ->and($group->getIcon())->toBe(Heroicon::OutlinedWrenchScrewdriver);
});
