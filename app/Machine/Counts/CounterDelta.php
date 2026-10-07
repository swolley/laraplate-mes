<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Counts;

/**
 * The pieces a counter signal reports between two samples. A delta counter reports them directly; a
 * cumulative one is compared with the previous sample, recognising a rollover (the counter passed its highest value,
 * `rollover_max`, and went back to 0: the wrap itself is one count) and a reset (it started again from zero). The first cumulative sample is only a baseline.
 */
final class CounterDelta
{
    public static function between(?float $previous, float $value, string $mode, ?float $rollover_max): float
    {
        if ($mode === 'delta') {
            return max(0.0, $value);
        }

        if ($previous === null) {
            return 0.0;
        }

        if ($value >= $previous) {
            return $value - $previous;
        }

        if ($rollover_max !== null && $previous > $rollover_max / 2) {
            return max(0.0, $rollover_max - $previous + $value + 1.0);
        }

        return max(0.0, $value);
    }
}
