<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Override;

final class ListBoms extends ListRecords
{
    #[Override]
    protected static string $resource = BomResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
