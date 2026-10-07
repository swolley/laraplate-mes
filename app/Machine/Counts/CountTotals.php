<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Counts;

/**
 * What one device counted, reduced to good and total pieces. A device that sends a total counter has its
 * total as sent, and when it sends no good counter the good pieces are the total minus the scrap; a device
 * without a total counter has good plus scrap as its total. Decided per device, never across devices.
 */
final class CountTotals
{
    /**
     * @return array{good: float, total: float}
     */
    public static function effective(float $good, float $scrap, float $total): array
    {
        if ($total > 0.0) {
            return ['good' => $good > 0.0 ? $good : max(0.0, $total - $scrap), 'total' => $total];
        }

        return ['good' => $good, 'total' => $good + $scrap];
    }
}
