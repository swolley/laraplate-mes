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
    case RoutingOperations = 'mes_routing_operations';
    case ProductionOrders = 'mes_production_orders';
    case ProductionOrderOperations = 'mes_production_order_operations';
    case MaterialConsumptions = 'mes_material_consumptions';
    case LotNumbers = 'mes_lot_numbers';
    case SerialNumbers = 'mes_serial_numbers';
    case LotLineages = 'mes_lot_lineages';
    case QualityChecks = 'mes_quality_checks';
    case QualityCheckMeasurements = 'mes_quality_check_measurements';
    case NonConformances = 'mes_non_conformances';
}
