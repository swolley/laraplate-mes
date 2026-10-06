<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Modules\MES\Enums\MachineState;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;

/**
 * A sample matched to its configured signal and, when possible, to the operation it belongs to.
 */
final readonly class ResolvedSample
{
    public function __construct(
        public MachineDevice $device,
        public MachineSignal $signal,
        public NormalizedSample $sample,
        public ?int $production_order_operation_id = null,
        public ?MachineState $state = null,
        public ?string $alarm_code = null,
    ) {}
}
