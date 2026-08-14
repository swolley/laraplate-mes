<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\QualityChecks\QualityCheckResource;
use Override;

final class ListQualityChecks extends ListRecords
{
    #[Override]
    protected static string $resource = QualityCheckResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
