<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\Boms\BomResource;
use Override;

final class EditBom extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = BomResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
