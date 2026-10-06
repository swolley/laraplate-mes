<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Carbon\CarbonImmutable;

/**
 * A birth (the device announces its signals) or a death (the device goes away).
 */
final readonly class DeviceNotice
{
    public const string BIRTH = 'birth';

    public const string DEATH = 'death';

    /**
     * @param  string  $type  `birth` or `death`
     * @param  list<array{signal: string, data_type: string, unit: ?string}>  $signals  empty for a death
     */
    public function __construct(
        public string $device,
        public string $type,
        public CarbonImmutable $ts,
        public array $signals = [],
    ) {}
}
