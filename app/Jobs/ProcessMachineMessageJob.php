<?php

declare(strict_types=1);

namespace Modules\MES\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Machine\MachineIncidentRecorder;
use Modules\MES\Machine\MachineMessageProcessor;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Throwable;

/**
 * Processes one stored machine message. Messages of a source are processed one at a time, in the
 * order they were queued, which is the order the agent sent them. Running it again leaves the data
 * as one run would; `$reprocess` also keeps the unmapped counters from growing. Order is kept per
 * source by the overlap guard only while one worker serves the queue: a released message goes to the back.
 */
final class ProcessMachineMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Unlimited attempts, because being released while another message of the source runs (the
     * overlap guard) counts as an attempt: the budget is exceptions, not releases.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(
        public int $machine_message_id,
        public int $source_id,
        public bool $reprocess = false,
    ) {
        $this->onConnection(config()->string('mes.queue.connection'));
        $this->onQueue(config()->string('mes.machine.queue'));
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->source_id)->releaseAfter(30)->expireAfter(300)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(NormalizerRegistry $registry, MachineMessageProcessor $processor): void
    {
        $message = MachineMessage::query()->withoutGlobalScopes()->find($this->machine_message_id);
        $source = MachineSource::query()->withoutGlobalScopes()->find($this->source_id);

        if (! $message instanceof MachineMessage || ! $source instanceof MachineSource) {
            return;
        }

        $message->update(['attempts' => $message->attempts + 1]);

        try {
            $normalized = $registry->for($source)->normalize($source, $message->payload);
        } catch (UnreadableMachinePayload $unreadable) {
            $this->markFailed($message, $source, $unreadable->getMessage());

            return;
        }

        // Unmapped counters grow on the first attempt of a first processing only: a retry after a late failure must not count again.
        $processor->process($source, $normalized, $message->received_at, ! $this->reprocess && $message->attempts === 1);

        $message->update(['status' => MachineMessageStatus::Processed->value, 'processed_at' => now(), 'error' => null]);
    }

    /**
     * Called by the queue after the last attempt.
     */
    public function failed(Throwable $exception): void
    {
        $message = MachineMessage::query()->withoutGlobalScopes()->find($this->machine_message_id);
        $source = MachineSource::query()->withoutGlobalScopes()->find($this->source_id);

        if ($message instanceof MachineMessage && $source instanceof MachineSource) {
            $this->markFailed($message, $source, $exception->getMessage());
        }
    }

    private function markFailed(MachineMessage $message, MachineSource $source, string $error): void
    {
        $message->update(['status' => MachineMessageStatus::Failed->value, 'error' => $error]);

        resolve(MachineIncidentRecorder::class)->record($source, MachineIncidentType::MessageFailed, [
            'machine_message_id' => $message->id,
            'error' => $error,
        ]);
    }
}
