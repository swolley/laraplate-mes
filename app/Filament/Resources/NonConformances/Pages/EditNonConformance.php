<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\NonConformances\NonConformanceResource;
use Override;

final class EditNonConformance extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = NonConformanceResource::class;
}
