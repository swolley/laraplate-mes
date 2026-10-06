<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;

/**
 * A `(source, device, signal)` triple resolved to its configured records.
 */
final readonly class ResolvedSignal
{
    public function __construct(
        public MachineDevice $device,
        public MachineSignal $signal,
        public int $work_center_id,
        public int $company_id,
    ) {}
}
