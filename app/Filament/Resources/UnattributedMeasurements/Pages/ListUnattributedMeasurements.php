<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\UnattributedMeasurements\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\UnattributedMeasurements\UnattributedMeasurementResource;
use Override;

final class ListUnattributedMeasurements extends ListRecords
{
    #[Override]
    protected static string $resource = UnattributedMeasurementResource::class;
}
