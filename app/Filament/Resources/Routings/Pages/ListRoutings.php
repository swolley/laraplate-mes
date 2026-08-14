<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Routings\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\Routings\RoutingResource;
use Override;

final class ListRoutings extends ListRecords
{
    #[Override]
    protected static string $resource = RoutingResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
