<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Override;

final class CreateWorkCenter extends CreateRecord
{
    #[Override]
    protected static string $resource = WorkCenterResource::class;
}
