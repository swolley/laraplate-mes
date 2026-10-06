<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

final readonly class SparkplugPayload
{
    /**
     * @param  list<SparkplugMetric>  $metrics
     */
    public function __construct(
        public ?int $timestamp_ms,
        public ?int $seq,
        public array $metrics,
    ) {}
}
