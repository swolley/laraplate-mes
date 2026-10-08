<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Process;

use Carbon\CarbonImmutable;

/**
 * What one signal did while an operation ran, as a {@see ProcessValueStore} computes it.
 */
final readonly class ProcessStatistics
{
    public function __construct(
        public int $signal_id,
        public float $min,
        public float $max,
        public float $avg,
        public int $count,
        public int $out_of_range_count,
        public CarbonImmutable $first_ts,
        public CarbonImmutable $last_ts,
    ) {}
}
