<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\QualityChecks\QualityCheckResource;
use Override;

final class EditQualityCheck extends EditRecord
{
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
