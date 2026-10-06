<?php

declare(strict_types=1);

namespace Modules\MES\Data;

/**
 * OEE and capacity of one work center for one day, as materialised by
 * {@see \Modules\MES\Services\WorkCenterKpiMaterializer}.
 */
final readonly class WorkCenterKpis
{
    public function __construct(
        public int $work_center_id,
        public string $day,
        public float $availability,
        public float $performance,
        public float $quality,
        public float $oee,
        public float $capacity_load,
        public float $available_minutes,
        public bool $overloaded,
        public string $computed_at,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
