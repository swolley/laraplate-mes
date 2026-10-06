<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\MachineMessageReprocessor;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

it('puts a failed message back to pending and queues its reprocessing', function (): void {
    Queue::fake();
    $message = MachineMessage::factory()->failed()->create();

    $reprocessed = resolve(MachineMessageReprocessor::class)->reprocess($message);

    expect($reprocessed->status)->toBe(MachineMessageStatus::Pending)
        ->and($reprocessed->error)->toBeNull();
    Queue::assertPushed(ProcessMachineMessageJob::class, static fn (ProcessMachineMessageJob $job): bool => $job->machine_message_id === $message->id && $job->reprocess === true);
});

it('reprocesses a range of one source, oldest first', function (): void {
    Queue::fake();
    $source = MachineSource::factory()->create();
    $other = MachineSource::factory()->create();
    $late = MachineMessage::factory()->processed()->create(['source_id' => $source->id, 'received_at' => '2026-10-05 12:00:00']);
    $early = MachineMessage::factory()->processed()->create(['source_id' => $source->id, 'received_at' => '2026-10-05 10:00:00']);
    MachineMessage::factory()->processed()->create(['source_id' => $source->id, 'received_at' => '2026-10-06 10:00:00']);
    MachineMessage::factory()->processed()->create(['source_id' => $other->id, 'received_at' => '2026-10-05 11:00:00']);

    $count = resolve(MachineMessageReprocessor::class)->reprocessRange($source, Carbon::parse('2026-10-05 00:00:00'), Carbon::parse('2026-10-05 23:59:59'));

    expect($count)->toBe(2);
    $order = [];
    Queue::assertPushed(ProcessMachineMessageJob::class, static function (ProcessMachineMessageJob $job) use (&$order): bool {
        $order[] = $job->machine_message_id;

        return true;
    });
    expect($order)->toBe([$early->id, $late->id]);
});

it('a mapping fix followed by reprocess resolves the sample and keeps the other counters', function (): void {
    config(['mes.queue.connection' => 'sync']);
    Event::fake([ProcessValuesSampled::class]);
    $source = MachineSource::factory()->create();
    $device = MachineDevice::factory()->create(['source_id' => $source->id, 'external_id' => 'press-07']);
    $payload = json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => 'm-fix', 'source_seq' => 1, 'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'press-07', 'type' => 'data', 'samples' => [
            ['signal' => 's1', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 1],
            ['signal' => 's2', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 2],
        ]]],
    ], JSON_THROW_ON_ERROR);
    $message = MachineMessage::factory()->create(['source_id' => $source->id, 'message_id' => 'm-fix', 'payload' => $payload]);
    (new ProcessMachineMessageJob($message->id, $source->id))->handle(resolve(Modules\MES\Machine\Normalizers\NormalizerRegistry::class), resolve(Modules\MES\Machine\MachineMessageProcessor::class));
    expect(UnmappedSignal::query()->pluck('seen_count', 'signal_key')->all())->toBe(['s1' => 1, 's2' => 1]);
    Event::assertNotDispatched(ProcessValuesSampled::class);

    MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 's1', 'role' => SignalRole::ProcessValue->value, 'config' => null]);
    resolve(MachineMessageReprocessor::class)->reprocess($message->fresh());

    Event::assertDispatched(ProcessValuesSampled::class, static fn (ProcessValuesSampled $event): bool => $event->samples[0]->sample->signal === 's1');
    expect(UnmappedSignal::query()->pluck('seen_count', 'signal_key')->all())->toBe(['s1' => 1, 's2' => 1])
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed);
});
