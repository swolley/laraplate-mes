<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\Downtimes\DowntimeResource;
use Override;

final class ListDowntimes extends ListRecords
{
    #[Override]
    protected static string $resource = DowntimeResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
