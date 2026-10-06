<?php

declare(strict_types=1);

namespace Modules\MES\Machine;

use Carbon\CarbonImmutable;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Enums\MESTables;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\Data\InboxResult;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Machine\Support\IdempotentWriter;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;

/**
 * The single entry of machine messages, for HTTP and for the MQTT bridge. It stores the raw
 * message durably and queues its processing; no domain logic runs here.
 */
final class MachineMessageInbox
{
    public function __construct(
        private readonly NormalizerRegistry $registry,
        private readonly IdempotentWriter $writer,
        private readonly MachineIncidentRecorder $incidents,
    ) {}

    /**
     * @throws UnreadableMachinePayload when the source's normaliser cannot read the payload.
     */
    public function accept(MachineSource $source, string $payload, MachineTransport $transport): InboxResult
    {
        $meta = $this->registry->for($source)->meta($source, $payload);
        $received_at = now();

        $inserted = $this->writer->insert(new MachineMessage()->getConnection(), MESTables::MachineMessages->value, [
            'company_id' => $source->company_id,
            'source_id' => $source->id,
            'message_id' => $meta->message_id,
            'source_seq' => $meta->source_seq,
            'transport' => $transport->value,
            'payload' => $payload,
            'received_at' => $received_at,
            'status' => MachineMessageStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $received_at,
            'updated_at' => $received_at,
        ]);

        $message = MachineMessage::query()
            ->withoutGlobalScopes()
            ->where('source_id', $source->id)
            ->where('message_id', $meta->message_id)
            ->firstOrFail();

        if (! $inserted) {
            return new InboxResult(true, $message);
        }

        MachineSource::query()->withoutGlobalScopes()->toBase()->where('id', $source->id)->update(['last_seen_at' => $received_at]);
        $this->checkSequence($source, $meta);
        $this->checkClock($source, $meta);

        ProcessMachineMessageJob::dispatch($message->id, $source->id);

        return new InboxResult(false, $message);
    }

    private function checkSequence(MachineSource $source, MessageMeta $meta): void
    {
        if ($meta->source_seq === null) {
            return;
        }

        $last = MachineSource::query()->withoutGlobalScopes()->toBase()->where('id', $source->id)->value('last_seq');

        if (is_numeric($last) && $meta->source_seq > (int) $last + 1) {
            $this->incidents->record($source, MachineIncidentType::SeqGap, ['expected' => (int) $last + 1, 'received' => $meta->source_seq]);
        }

        MachineSource::query()->withoutGlobalScopes()->toBase()
            ->where('id', $source->id)
            ->where(static fn ($query) => $query->whereNull('last_seq')->orWhere('last_seq', '<', $meta->source_seq))
            ->update(['last_seq' => $meta->source_seq]);
    }

    private function checkClock(MachineSource $source, MessageMeta $meta): void
    {
        if (! $meta->sent_at instanceof CarbonImmutable) {
            return;
        }

        $skew = (int) round(abs(now()->diffInSeconds($meta->sent_at, false)));

        if ($skew > config()->integer('mes.machine.clock_skew_seconds')) {
            $this->incidents->record($source, MachineIncidentType::ClockSkew, [
                'sent_at' => $meta->sent_at->toIso8601ZuluString('millisecond'),
                'received_at' => now()->toIso8601ZuluString('millisecond'),
                'skew_seconds' => $skew,
            ]);
        }
    }
}
