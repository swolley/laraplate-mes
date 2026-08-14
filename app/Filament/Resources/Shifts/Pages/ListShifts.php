<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Shifts\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\Shifts\ShiftResource;
use Override;

final class ListShifts extends ListRecords
{
    #[Override]
    protected static string $resource = ShiftResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
