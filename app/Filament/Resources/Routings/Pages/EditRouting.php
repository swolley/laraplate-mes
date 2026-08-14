<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Routings\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\Routings\RoutingResource;
use Override;

final class EditRouting extends EditRecord
{
    #[Override]
    protected static string $resource = RoutingResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
