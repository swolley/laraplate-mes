<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Sparkplug;

/**
 * One metric of a Sparkplug B payload, its value already converted by its datatype.
 */
final readonly class SparkplugMetric
{
    public function __construct(
        public ?string $name,
        public ?int $alias,
        public ?int $timestamp_ms,
        public int $datatype,
        public bool $is_null,
        public bool $is_historical,
        public int|float|bool|string|null $value,
        public bool $supported,
    ) {}
}
