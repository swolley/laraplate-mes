<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\MES\Machine\Data\ResolvedSample;

/**
 * The samples of one message from one device with the roles State and Alarm, in time order.
 * Nothing consumes it until the step that owns that role.
 */
final class MachineStateObserved
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<ResolvedSample>  $samples
     */
    public function __construct(
        public readonly int $company_id,
        public readonly int $device_id,
        public readonly int $work_center_id,
        public readonly array $samples,
    ) {}
}
