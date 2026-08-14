<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a material consumption (backflush or manual) requires more of an
 * item than is available in the target warehouse. Non-blocking: the available
 * quantity is consumed and the shortfall is flagged on the consumption; this
 * event is the hook for notification, dashboards or replenishment.
 */
final class MaterialShortageDetected
{
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly int $item_id,
        public readonly int $warehouse_id,
        public readonly int $production_order_id,
        public readonly ?int $production_order_operation_id,
        public readonly float $required_quantity,
        public readonly float $available_quantity,
        public readonly bool $is_backflush,
    ) {}
}
