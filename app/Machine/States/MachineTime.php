<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The form of a moment in the machine tables: the application timezone and milliseconds. Query bindings
 * of a Carbon value drop the milliseconds, so every comparison with an interval or a machine downtime
 * time goes through this string.
 */
final class MachineTime
{
    /**
     * The same instant in the application timezone, which is how the columns read back what they were given.
     */
    public static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(config()->string('app.timezone'));
    }

    public static function db(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s.v');
    }
}
