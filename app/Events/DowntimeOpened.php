<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted after the downtime transition it names has been persisted.
 */
final class DowntimeOpened
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly int $work_center_id,
        public readonly int $downtime_id,
        public readonly string $cause,
    ) {}
}
