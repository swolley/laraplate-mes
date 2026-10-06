<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\MachineDevices\MachineDeviceResource;
use Override;

final class ListMachineDevices extends ListRecords
{
    #[Override]
    protected static string $resource = MachineDeviceResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
