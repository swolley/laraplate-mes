<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Process;

use Carbon\CarbonImmutable;

/**
 * The aggregate of one signal over one bucket: resolution `1m` or `1h`.
 */
final readonly class ProcessAggregateRow
{
    public function __construct(
        public int $signal_id,
        public string $resolution,
        public CarbonImmutable $bucket_start,
        public float $min,
        public float $max,
        public float $avg,
        public float $last,
        public int $count,
    ) {}
}
