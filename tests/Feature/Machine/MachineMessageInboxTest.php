<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Events\MachineIncidentRecorded;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\MachineIncidentRecorder;
use Modules\MES\Machine\MachineMessageInbox;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Support\IdempotentWriter;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\MesTestHelpers;
use Modules\MES\Tests\Support\StubNormalizer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Queue::fake();
});

function inboxPayload(string $message_id, ?int $seq = 1, ?string $sent_at = null): string
{
    return json_encode([
        'protocol' => 'laraplate-machine/1',
        'message_id' => $message_id,
        'source_seq' => $seq ?? 0,
        'sent_at' => $sent_at ?? now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'd1', 'type' => 'data', 'samples' => [['signal' => 's', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 1]]]],
    ], JSON_THROW_ON_ERROR);
}

it('stores a new message as pending and queues one job on the machine queue', function (): void {
    $source = MachineSource::factory()->create();

    $result = resolve(MachineMessageInbox::class)->accept($source, inboxPayload('m-1'), MachineTransport::Http);

    expect($result->duplicate)->toBeFalse()
        ->and($result->message->status)->toBe(MachineMessageStatus::Pending)
        ->and($result->message->message_id)->toBe('m-1')
        ->and(MachineMessage::query()->count())->toBe(1)
        ->and($source->fresh()->last_seen_at)->not->toBeNull();
    Queue::assertPushedOn('mes-machine', ProcessMachineMessageJob::class, static fn (ProcessMachineMessageJob $job): bool => $job->machine_message_id === $result->message->id && $job->source_id === $source->id && $job->reprocess === false);
});

it('answers a resend with the stored message and queues nothing more', function (): void {
    $source = MachineSource::factory()->create();
    $inbox = resolve(MachineMessageInbox::class);
    $inbox->accept($source, inboxPayload('m-1'), MachineTransport::Http);

    $again = $inbox->accept($source, inboxPayload('m-1'), MachineTransport::Http);

    expect($again->duplicate)->toBeTrue()
        ->and(MachineMessage::query()->count())->toBe(1);
    Queue::assertPushed(ProcessMachineMessageJob::class, 1);
});

it('concurrent duplicate accept stores one message and dispatches one job', function (): void {
    $source = MachineSource::factory()->create(['normalizer' => 'stub']);
    // The racing winner stores the same message between the loser's metadata read and its insert.
    resolve(NormalizerRegistry::class)->register(new StubNormalizer('stub', static function (string $payload) use ($source): MessageMeta {
        resolve(IdempotentWriter::class)->insert(new MachineMessage()->getConnection(), 'mes_machine_messages', [
            'company_id' => $source->company_id,
            'source_id' => $source->id,
            'message_id' => 'race',
            'transport' => 'http',
            'payload' => $payload,
            'received_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new MessageMeta('race');
    }));

    $result = resolve(MachineMessageInbox::class)->accept($source, '{"x":1}', MachineTransport::Http);

    expect($result->duplicate)->toBeTrue()
        ->and(MachineMessage::query()->where('message_id', 'race')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('records a gap in the source sequence once, and never moves the sequence backwards', function (): void {
    $source = MachineSource::factory()->create();
    $inbox = resolve(MachineMessageInbox::class);

    $inbox->accept($source, inboxPayload('a', 5), MachineTransport::Http);
    expect(MachineIncident::query()->count())->toBe(0);

    $inbox->accept($source, inboxPayload('b', 8), MachineTransport::Http);
    $gap = MachineIncident::query()->sole();
    expect($gap->type)->toBe(MachineIncidentType::SeqGap)
        ->and($gap->detail)->toBe(['expected' => 6, 'received' => 8])
        ->and($source->fresh()->last_seq)->toBe(8);

    $inbox->accept($source, inboxPayload('c', 3), MachineTransport::Http);
    expect(MachineIncident::query()->count())->toBe(1)
        ->and($source->fresh()->last_seq)->toBe(8);
});

it('records a clock skew incident but still accepts the message', function (): void {
    $source = MachineSource::factory()->create();
    $inbox = resolve(MachineMessageInbox::class);

    $inbox->accept($source, inboxPayload('late', 1, now()->addMinutes(5)->toIso8601ZuluString('millisecond')), MachineTransport::Http);
    $inbox->accept($source, inboxPayload('fine', 2, now()->addSeconds(5)->toIso8601ZuluString('millisecond')), MachineTransport::Http);

    $incident = MachineIncident::query()->sole();
    expect($incident->type)->toBe(MachineIncidentType::ClockSkew)
        ->and($incident->detail)->toHaveKeys(['sent_at', 'received_at', 'skew_seconds'])
        ->and($incident->resolved_at)->not->toBeNull()
        ->and(MachineMessage::query()->count())->toBe(2);
});

it('keeps one clock skew incident open while the skew lasts', function (): void {
    $source = MachineSource::factory()->create();
    $inbox = resolve(MachineMessageInbox::class);

    foreach (['a', 'b', 'c'] as $id) {
        $inbox->accept($source, inboxPayload($id, null, now()->addMinutes(5)->toIso8601ZuluString('millisecond')), MachineTransport::Http);
    }

    expect(MachineIncident::query()->where('type', MachineIncidentType::ClockSkew->value)->whereNull('resolved_at')->count())->toBe(1)
        ->and(MachineIncident::query()->count())->toBe(1);
});

it('records an incident once while it stays open, and resolves it', function (): void {
    Event::fake([MachineIncidentRecorded::class]);
    $source = MachineSource::factory()->create();
    $recorder = resolve(MachineIncidentRecorder::class);

    $first = $recorder->recordOnce($source, MachineIncidentType::BridgeDown);
    $second = $recorder->recordOnce($source, MachineIncidentType::BridgeDown);

    expect($second->id)->toBe($first->id)
        ->and(MachineIncident::query()->count())->toBe(1)
        ->and($recorder->resolve($source, MachineIncidentType::BridgeDown))->toBe(1)
        ->and($first->fresh()->resolved_at)->not->toBeNull();
    Event::assertDispatchedTimes(MachineIncidentRecorded::class, 1);

    $recorder->recordOnce($source, MachineIncidentType::BridgeDown);
    expect(MachineIncident::query()->count())->toBe(2);
});
