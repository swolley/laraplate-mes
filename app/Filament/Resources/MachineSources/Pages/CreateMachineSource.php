<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\MachineSources\MachineSourceResource;
use Override;

final class CreateMachineSource extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineSourceResource::class;
}
