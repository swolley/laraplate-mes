<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Shifts\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\Shifts\ShiftResource;
use Override;

final class EditShift extends EditRecord
{
    #[Override]
    protected static string $resource = ShiftResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
