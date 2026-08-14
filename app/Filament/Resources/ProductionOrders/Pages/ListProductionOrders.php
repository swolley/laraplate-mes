<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Override;

final class ListProductionOrders extends ListRecords
{
    #[Override]
    protected static string $resource = ProductionOrderResource::class;
}
