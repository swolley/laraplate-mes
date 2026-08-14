<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Override;

final class ListWorkCenters extends ListRecords
{
    #[Override]
    protected static string $resource = WorkCenterResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
