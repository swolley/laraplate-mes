<?php

declare(strict_types=1);

namespace Modules\MES\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes one stored machine message: normalise, resolve, attribute, dispatch.
 */
final class ProcessMachineMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $machine_message_id,
        public int $source_id,
        public bool $reprocess = false,
    ) {
        $this->onConnection(config()->string('mes.queue.connection'));
        $this->onQueue(config()->string('mes.machine.queue'));
    }

    public function handle(): void
    {
        // Filled in by the processing task.
    }
}
