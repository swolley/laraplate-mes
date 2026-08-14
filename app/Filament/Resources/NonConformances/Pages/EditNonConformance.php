<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\MES\Filament\Resources\NonConformances\NonConformanceResource;
use Override;

final class EditNonConformance extends EditRecord
{
    #[Override]
    protected static string $resource = NonConformanceResource::class;
}
