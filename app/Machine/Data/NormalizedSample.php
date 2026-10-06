<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Carbon\CarbonImmutable;
use Modules\MES\Enums\SampleQuality;

/**
 * One reading of one signal, as the source's normaliser hands it to the pipeline.
 */
final readonly class NormalizedSample
{
    /**
     * @param  array<string, string>  $context  `order_ref`, `operation_ref`, `serial`, `lot`
     */
    public function __construct(
        public string $device,
        public string $signal,
        public CarbonImmutable $ts,
        public int|float|bool|string $value,
        public SampleQuality $quality = SampleQuality::Good,
        public array $context = [],
    ) {}
}
