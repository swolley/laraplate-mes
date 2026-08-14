<?php

declare(strict_types=1);

namespace Modules\MES\Enums;

/**
 * Why a sales order line did not yield a production order during auto-creation.
 */
enum ProductionPlanningSkipReason: string
{
    case NoItem = 'no_item';
    case NothingToProduce = 'nothing_to_produce';
    case AlreadyPlanned = 'already_planned';
    case NotManufactured = 'not_manufactured';
    case WarehouseUnresolved = 'warehouse_unresolved';
}
