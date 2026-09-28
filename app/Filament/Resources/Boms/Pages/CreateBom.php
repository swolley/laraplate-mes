<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Override;

final class CreateBom extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = BomResource::class;
}
