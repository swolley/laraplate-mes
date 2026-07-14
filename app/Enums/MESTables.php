<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum MESTables: string
{
    use HasModuleTablesUtils;
    
    case WorkCenters = 'mes_work_centers';
    case WorkCenterCalendars = 'mes_work_center_calendars';
    case Boms = 'mes_boms';
    case BomLines = 'mes_bom_lines';
    case Routings = 'mes_routings';
    case ProductionOrders = 'mes_production_orders';
}
