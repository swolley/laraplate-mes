<?php

declare(strict_types=1);

namespace Modules\MES\Machine\States;

use Modules\MES\Models\MachineStateInterval;

/**
 * What recording a state did to the history of a device: the intervals it created or changed, and the
 * starts (in {@see MachineTime::db()} form) of intervals it merged away, whose downtimes have to go with them.
 */
final readonly class MachineStateChange
{
    /**
     * @param  list<MachineStateInterval>  $changed
     * @param  list<string>  $removed_starts
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
