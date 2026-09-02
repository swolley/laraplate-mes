<?php

declare(strict_types=1);

namespace Modules\MES\Authorization;

use Modules\Core\Authorization\Contracts\DeclaresPermissions;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Override;

/**
 * MES domain permissions, checked by {@see \Modules\MES\Policies\MesModelPolicy}.
 */
final class MESPermissions implements DeclaresPermissions
{
    #[Override]
    public static function operations(): array
    {
        return [
            Bom::class => ['explode'],
            Downtime::class => ['close'],
            LotNumber::class => ['forward_trace', 'backward_trace'],
            NonConformance::class => ['resolve', 'close'],
            ProductionOrder::class => ['release', 'complete', 'cancel', 'record_consumption'],
            ProductionOrderOperation::class => ['start', 'complete', 'skip'],
            QualityCheck::class => ['execute'],
        ];
    }

    #[Override]
    public static function excludedModels(): array
    {
        return [];
    }
}
