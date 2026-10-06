<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted after the production order transition it names has been persisted.
 */
final class ProductionOrderReleased
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly int $production_order_id,
    ) {}
}
