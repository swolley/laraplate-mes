<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Process;

use Carbon\CarbonImmutable;
use Modules\MES\Enums\SampleQuality;

/**
 * One process value reading on its way into a {@see ProcessValueStore}.
 */
final readonly class ProcessSample
{
    public function __construct(
        public int $company_id,
        public int $signal_id,
        public int $device_id,
        public int $work_center_id,
        public ?int $production_order_operation_id,
        public CarbonImmutable $ts,
        public float $value,
        public SampleQuality $quality = SampleQuality::Good,
    ) {}
}
