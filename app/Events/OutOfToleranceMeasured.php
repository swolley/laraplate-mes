<?php

declare(strict_types=1);

namespace Modules\MES\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a probe measurement is stored for the first time and lies outside the limits of its plan
 * characteristic, before the quality check resolves. `quality_check_id` is null while the measurement waits
 * for a check. It is the hook for the notification.
 */
final class OutOfToleranceMeasured
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $company_id,
        public readonly ?int $quality_check_id,
        public readonly int $signal_id,
        public readonly string $characteristic,
        public readonly float $value,
        public readonly ?float $lower,
        public readonly ?float $upper,
    ) {}
}
