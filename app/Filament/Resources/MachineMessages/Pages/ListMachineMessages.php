<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineMessages\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\MachineMessages\MachineMessageResource;
use Override;

final class ListMachineMessages extends ListRecords
{
    #[Override]
    protected static string $resource = MachineMessageResource::class;
}
