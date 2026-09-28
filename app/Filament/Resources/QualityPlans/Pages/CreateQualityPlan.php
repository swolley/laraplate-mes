<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\QualityPlans\QualityPlanResource;
use Override;

final class CreateQualityPlan extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = QualityPlanResource::class;
}
