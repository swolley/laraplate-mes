<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\NonConformances\NonConformanceResource;
use Override;

final class ListNonConformances extends ListRecords
{
    #[Override]
    protected static string $resource = NonConformanceResource::class;
}
