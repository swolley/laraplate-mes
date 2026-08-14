<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\WorkCenters\WorkCenterResource;
use Override;

final class EditWorkCenter extends EditRecord
{
    #[Override]
    protected static string $resource = WorkCenterResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
