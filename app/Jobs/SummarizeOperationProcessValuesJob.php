<?php

declare(strict_types=1);

namespace Modules\MES\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\MES\Services\ProcessSummarizer;

/**
 * Brings the process summaries of an operation up to date. It scans every sample of the operation, so it runs
 * on the queue, never inside completing the operation or storing a message; queued once per operation until it
 * starts, so a buffer of late samples costs one scan, not one per message.
 */
final class SummarizeOperationProcessValuesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $production_order_operation_id)
    {
        $this->onConnection(config()->string('mes.queue.connection'));
        $this->onQueue(config()->string('mes.queue.name'));
    }

    public function uniqueId(): string
    {
        return (string) $this->production_order_operation_id;
    }

    public function handle(ProcessSummarizer $summarizer): void
    {
        $summarizer->summarize($this->production_order_operation_id);
    }
}
