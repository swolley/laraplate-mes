<?php

declare(strict_types=1);

namespace Modules\MES\Data;

/**
 * Data Transfer Object for recording a stock movement.
 *
 * Used by StockMovementRecorder to pass movement data
 * from the MES layer to the ERP inventory service.
 */
final readonly class StockMovementData
{
    public function __construct(
        public int $item_id,
        public int $warehouse_id,
        public int $company_id,
        /** @var string 'in'|'out' */
        public string $direction,
        public int $quantity,
        /** @var string e.g. 'mes_production_orders' */
        public string $source_type,
        public int $source_id,
        public \DateTimeInterface $occurred_at,
    ) {}
}
