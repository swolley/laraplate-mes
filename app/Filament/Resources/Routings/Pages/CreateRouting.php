<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Routings\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\MES\Filament\Resources\Routings\RoutingResource;
use Override;

final class CreateRouting extends CreateRecord
{
    #[Override]
    protected static string $resource = RoutingResource::class;
}
