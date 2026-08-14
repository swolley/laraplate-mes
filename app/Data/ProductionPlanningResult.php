<?php

declare(strict_types=1);

namespace Modules\MES\Data;

use Modules\MES\Enums\ProductionPlanningSkipReason;
use Modules\MES\Models\ProductionOrder;

/**
 * Outcome of planning production orders for a confirmed sales order: the orders
 * created and, for auditing, the lines that were skipped with the reason.
 */
final readonly class ProductionPlanningResult
{
    /**
     * @param  list<ProductionOrder>  $created
     * @param  array<int, ProductionPlanningSkipReason>  $skipped  keyed by sales order line id
     */
    public function __construct(
        public array $created = [],
        public array $skipped = [],
    ) {}
}
