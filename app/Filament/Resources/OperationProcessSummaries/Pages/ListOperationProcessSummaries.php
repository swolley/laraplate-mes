<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\OperationProcessSummaries\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\MES\Filament\Resources\OperationProcessSummaries\OperationProcessSummaryResource;
use Override;

final class ListOperationProcessSummaries extends ListRecords
{
    #[Override]
    protected static string $resource = OperationProcessSummaryResource::class;
}
