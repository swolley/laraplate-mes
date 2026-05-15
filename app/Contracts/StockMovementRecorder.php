<?php

declare(strict_types=1);

namespace Modules\MES\Contracts;

use Modules\MES\Data\StockMovementData;

interface StockMovementRecorder
{
    public function record(StockMovementData $data): void;
}
