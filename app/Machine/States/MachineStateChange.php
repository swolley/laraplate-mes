<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Modules\MES\Models\MachineStateInterval;

/**
 * What recording a state did to the history of a device: the intervals it created or changed, and the
 * starts (in {@see MachineTime::db()} form) of intervals it merged away, each mapped to the start of the interval
 * that absorbed it, so the downtime of the absorbed one can move there instead of being lost.
 */
final readonly class MachineStateChange
{
    /**
     * @param  list<MachineStateInterval>  $changed
     * @param  array<string, string>  $removed_starts  removed start => start of the interval that took its place
     */
    public function __construct(
        public array $changed = [],
        public array $removed_starts = [],
    ) {}

    public function merge(self $other): self
    {
        return new self([...$this->changed, ...$other->changed], [...$this->removed_starts, ...$other->removed_starts]);
    }
}
