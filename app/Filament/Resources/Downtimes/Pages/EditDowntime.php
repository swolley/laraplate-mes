<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\Downtimes\DowntimeResource;
use Override;

final class EditDowntime extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = DowntimeResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
