<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\UnmappedSignals\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\UnmappedSignals\UnmappedSignalResource;
use Override;

final class ListUnmappedSignals extends ListRecords
{
    #[Override]
    protected static string $resource = UnmappedSignalResource::class;
}
