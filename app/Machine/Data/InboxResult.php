<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Data;

use Modules\MES\Models\MachineMessage;

final readonly class InboxResult
{
    public function __construct(
        public bool $duplicate,
        public MachineMessage $message,
    ) {}
}
