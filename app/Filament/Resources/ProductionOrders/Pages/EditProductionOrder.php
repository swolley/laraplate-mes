<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\ProductionOrders\ProductionOrderResource;
use Override;

final class EditProductionOrder extends EditRecord
{
    #[Override]
    protected static string $resource = ProductionOrderResource::class;
}
