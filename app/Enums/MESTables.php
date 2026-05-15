<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

enum MESTables: string
{
    case WorkCenters = 'mes_work_centers';
    case Boms = 'mes_boms';
}
