<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\MES\Filament\Resources\QualityPlans\QualityPlanResource;
use Override;

final class CreateQualityPlan extends CreateRecord
{
    #[Override]
    protected static string $resource = QualityPlanResource::class;
}
