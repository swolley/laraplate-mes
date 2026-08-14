<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Override;

final class CreateBom extends CreateRecord
{
    #[Override]
    protected static string $resource = BomResource::class;
}
