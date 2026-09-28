<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Override;

final class CreateWorkCenter extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = WorkCenterResource::class;
}
