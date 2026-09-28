<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\MES\Filament\Resources\QualityChecks\QualityCheckResource;
use Override;

final class EditQualityCheck extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = QualityCheckResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
