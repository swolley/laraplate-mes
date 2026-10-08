<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\OperationCompleted;
use Modules\MES\Jobs\SummarizeOperationProcessValuesJob;

/**
 * Queues the process summaries of an operation when it completes: the scan of its samples must not hold up
 * completing it, nor fail it.
 */
final class SummarizeOperationProcessValues
{
    public function handle(OperationCompleted $event): void
    {
        SummarizeOperationProcessValuesJob::dispatch($event->production_order_operation_id);
    }
}
