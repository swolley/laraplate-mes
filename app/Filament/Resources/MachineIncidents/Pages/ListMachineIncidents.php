<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineIncidents\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\MachineIncidents\MachineIncidentResource;
use Override;

final class ListMachineIncidents extends ListRecords
{
    #[Override]
    protected static string $resource = MachineIncidentResource::class;
}
