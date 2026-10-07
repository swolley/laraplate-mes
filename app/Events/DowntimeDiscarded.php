<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a downtime derived from the machine goes away (a late sample showed its stop to be a
 * micro-stop, or it merged into an earlier one), so that what was told by {@see DowntimeOpened} can be undone.
 */
final class DowntimeDiscarded
{
    use Dispatchable;

    public function __construct(
        public readonly int $company_id,
        public readonly int $work_center_id,
        public readonly int $downtime_id,
    ) {}
}
