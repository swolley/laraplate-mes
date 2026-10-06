<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\MachineSources\MachineSourceResource;
use Override;

final class ListMachineSources extends ListRecords
{
    #[Override]
    protected static string $resource = MachineSourceResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
