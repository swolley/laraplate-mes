<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\QualityPlans\QualityPlanResource;
use Override;

final class EditQualityPlan extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = QualityPlanResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
