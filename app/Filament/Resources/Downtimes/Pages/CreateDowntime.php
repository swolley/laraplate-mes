<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\MES\Filament\Resources\Downtimes\DowntimeResource;
use Override;

final class CreateDowntime extends CreateRecord
{
    #[Override]
    protected static string $resource = DowntimeResource::class;
}
