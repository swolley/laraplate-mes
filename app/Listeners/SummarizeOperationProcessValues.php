<?php

declare(strict_types=1);

namespace Modules\MES\Listeners;

use Modules\MES\Events\OperationCompleted;
use Modules\MES\Services\ProcessSummarizer;

/**
 * Writes the process summaries of an operation when it completes.
 */
final class SummarizeOperationProcessValues
{
    public function __construct(
        private readonly ProcessSummarizer $summarizer,
    ) {}

    public function handle(OperationCompleted $event): void
    {
        $this->summarizer->summarize($event->production_order_operation_id);
    }
}
