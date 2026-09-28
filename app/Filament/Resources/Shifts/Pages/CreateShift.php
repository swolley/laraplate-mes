<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Shifts\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\Shifts\ShiftResource;
use Override;

final class CreateShift extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = ShiftResource::class;
}
