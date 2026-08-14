<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\QualityPlans\QualityPlanResource;
use Override;

final class ListQualityPlans extends ListRecords
{
    #[Override]
    protected static string $resource = QualityPlanResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
