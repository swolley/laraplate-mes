<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted once per operation, when the good pieces the machine counted reach the order's planned
 * quantity. The machine never completes the operation: this is the hook for the notification.
 */
final class OperationTargetReached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly int $production_order_id,
        public readonly int $production_order_operation_id,
        public readonly int $work_center_id,
        public readonly float $good,
        public readonly float $planned,
    ) {}
}
