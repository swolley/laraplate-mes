<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when an operation starts on a work center whose materialised load for
 * the day exceeds its available minutes. Non-blocking: the operation starts, and
 * this event is the hook for the notification channel and dashboards.
 */
final class CapacityOverloadDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly int $work_center_id,
        public readonly int $production_order_id,
        public readonly int $production_order_operation_id,
        public readonly float $capacity_load,
        public readonly float $available_minutes,
    ) {}
}
