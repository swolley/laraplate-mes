<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonInterface;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;

/**
 * Runs stored messages through the pipeline again, typically after a mapping fix.
 */
final class MachineMessageReprocessor
{
    public function reprocess(MachineMessage $message): MachineMessage
    {
        $message->update(['status' => MachineMessageStatus::Pending->value, 'error' => null]);

        ProcessMachineMessageJob::dispatch($message->id, $message->source_id, true);

        return $message->refresh();
    }

    /**
     * Reprocesses the messages of a source received in a range, oldest first. One job per message,
     * so the per-source serialisation keeps their order.
     *
     * @return int how many messages were queued
     */
    public function reprocessRange(MachineSource $source, CarbonInterface $from, CarbonInterface $to): int
    {
        $count = 0;

        MachineMessage::query()
            ->withoutGlobalScopes()
            ->where('source_id', $source->id)
            ->whereBetween('received_at', [$from, $to])
            ->orderBy('received_at')
            ->orderBy('id')
            ->each(function (MachineMessage $message) use (&$count): void {
                $this->reprocess($message);
                $count++;
            });

        return $count;
    }
}
