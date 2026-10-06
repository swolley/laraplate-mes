<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineProfiles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\MachineProfiles\MachineProfileResource;
use Override;

final class CreateMachineProfile extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = MachineProfileResource::class;
}
