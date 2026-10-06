<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\MachineDevices\MachineDeviceResource;
use Override;

final class CreateMachineDevice extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineDeviceResource::class;
}
