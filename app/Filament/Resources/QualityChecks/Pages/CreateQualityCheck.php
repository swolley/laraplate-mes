<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\QualityChecks\QualityCheckResource;
use Override;

final class CreateQualityCheck extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = QualityCheckResource::class;
}
