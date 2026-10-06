<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Events\PartsCounted;
use Modules\MES\Events\ProbeMeasured;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Event::fake([MachineStateObserved::class, PartsCounted::class, ProbeMeasured::class, ProcessValuesSampled::class]);
});

/**
 * A source with one device `press-07` and one signal per role used in the tests.
 *
 * @return array{source: MachineSource, device: MachineDevice, signals: array<string, MachineSignal>}
 */
function pipelineSetup(): array
{
    $source = MachineSource::factory()->create();
    $device = MachineDevice::factory()->create(['source_id' => $source->id, 'external_id' => 'press-07']);
    $characteristic = QualityPlanCharacteristic::factory()->create();
    $configs = [
        'state' => [SignalRole::State, ['map' => ['EXECUTE' => 'running', 'STOPPED' => 'stopped']], null],
        'good_count' => [SignalRole::GoodCount, ['mode' => 'delta'], null],
        'bore_d' => [SignalRole::Measurement, null, $characteristic->id],
        'temp' => [SignalRole::ProcessValue, null, null],
        'order_ref' => [SignalRole::OrderReference, null, null],
    ];
    $signals = [];

    foreach ($configs as $key => [$role, $config, $characteristic_id]) {
        $signals[$key] = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => $key, 'role' => $role->value, 'config' => $config, 'quality_plan_characteristic_id' => $characteristic_id]);
    }

    return ['source' => $source, 'device' => $device, 'signals' => $signals];
}

/**
 * @param  list<array<string, mixed>>  $samples
 */
function storeMessage(MachineSource $source, array $samples, array $envelope = []): MachineMessage
{
    $payload = array_merge([
        'protocol' => 'laraplate-machine/1',
        'message_id' => (string) Illuminate\Support\Str::uuid(),
        'source_seq' => 1,
        'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'press-07', 'type' => 'data', 'samples' => $samples]],
    ], $envelope);

    return MachineMessage::factory()->create(['source_id' => $source->id, 'message_id' => $payload['message_id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
}

function sampleRow(string $signal, mixed $value, ?string $ts = null, array $extra = []): array
{
    return ['signal' => $signal, 'ts' => $ts ?? now()->toIso8601ZuluString('millisecond'), 'value' => $value] + $extra;
}

function runJob(MachineMessage $message, bool $reprocess = false): void
{
    $job = new ProcessMachineMessageJob($message->id, $message->source_id, $reprocess);
    app()->call([$job, 'handle']);
}

it('dispatches one event per device and role with the resolved samples', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [
        sampleRow('state', 'EXECUTE'),
        sampleRow('good_count', 3),
        sampleRow('bore_d', 12.004),
        sampleRow('temp', 71.5),
    ]);

    runJob($message);

    Event::assertDispatched(MachineStateObserved::class, static fn (MachineStateObserved $event): bool => $event->device_id === $ctx['device']->id
        && $event->work_center_id === $ctx['device']->work_center_id
        && count($event->samples) === 1
        && $event->samples[0]->state === MachineState::Running);
    Event::assertDispatched(PartsCounted::class, static fn (PartsCounted $event): bool => $event->samples[0]->sample->value === 3);
    Event::assertDispatched(ProbeMeasured::class, static fn (ProbeMeasured $event): bool => $event->samples[0]->sample->value === 12.004);
    Event::assertDispatched(ProcessValuesSampled::class, static fn (ProcessValuesSampled $event): bool => $event->samples[0]->sample->value === 71.5);
    expect($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->processed_at)->not->toBeNull()
        ->and($ctx['device']->fresh()->last_seen_at)->not->toBeNull();
});

it('keeps the samples of a role in time order', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [
        sampleRow('temp', 2, '2026-10-05T08:00:02.000Z'),
        sampleRow('temp', 1, '2026-10-05T08:00:01.000Z'),
    ]);

    runJob($message);

    Event::assertDispatched(ProcessValuesSampled::class, static fn (ProcessValuesSampled $event): bool => array_map(static fn ($s): int => $s->sample->value, $event->samples) === [1, 2]);
});

it('ignores bad quality samples', function (): void {
    $ctx = pipelineSetup();
    runJob(storeMessage($ctx['source'], [sampleRow('temp', 1, null, ['quality' => 'bad'])]));

    Event::assertNotDispatched(ProcessValuesSampled::class);
});

it('records an unknown device and an unknown signal as unmapped, without events', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [sampleRow('mystery', 5)], ['devices' => [
        ['device' => 'press-07', 'type' => 'data', 'samples' => [sampleRow('mystery', 5)]],
        ['device' => 'ghost-99', 'type' => 'data', 'samples' => [sampleRow('temp', 9)]],
    ]]);

    runJob($message);

    expect(UnmappedSignal::query()->orderBy('device_external_id')->get()->map(fn ($row): array => [$row->device_external_id, $row->signal_key, $row->seen_count])->all())
        ->toBe([['ghost-99', 'temp', 1], ['press-07', 'mystery', 1]]);
    Event::assertNotDispatched(ProcessValuesSampled::class);
});

it('processes a raw state value the map does not know, records it as unmapped and dispatches nothing for it', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [sampleRow('state', 'HOLDING'), sampleRow('temp', 1)]);

    runJob($message);

    expect($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and(UnmappedSignal::query()->sole()->signal_key)->toBe('state#HOLDING')
        ->and(UnmappedSignal::query()->sole()->last_value)->toBe('HOLDING');
    Event::assertNotDispatched(MachineStateObserved::class);
    Event::assertDispatched(ProcessValuesSampled::class);
});

it('attributes the samples of a message through its reference signal and does not dispatch the reference', function (): void {
    $ctx = pipelineSetup();
    $order = ProductionOrder::factory()->create(['company_id' => $ctx['device']->company_id, 'number' => 'PO-77']);
    $operation = ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $ctx['device']->work_center_id,
        'actual_start_at' => now()->subHour(),
    ]);
    // A second operation on the work center makes the single-active rule fail: only the reference attributes.
    ProductionOrderOperation::factory()->create(['work_center_id' => $ctx['device']->work_center_id, 'production_order_id' => ProductionOrder::factory()->create(['company_id' => $ctx['device']->company_id])->id, 'actual_start_at' => now()->subHour()]);

    runJob(storeMessage($ctx['source'], [sampleRow('order_ref', 'PO-77'), sampleRow('good_count', 1)]));

    Event::assertDispatched(PartsCounted::class, static fn (PartsCounted $event): bool => $event->samples[0]->production_order_operation_id === $operation->id);
    Event::assertNotDispatched(MachineStateObserved::class);
});

it('turns a death notice into an Offline state on the device state signal', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [], ['devices' => [['device' => 'press-07', 'type' => 'death']]]);

    runJob($message);

    Event::assertDispatched(MachineStateObserved::class, static fn (MachineStateObserved $event): bool => $event->samples[0]->state === MachineState::Offline
        && $event->samples[0]->signal->key === 'state');
});

it('records the signals a birth notice announces that nobody configured', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [], ['devices' => [['device' => 'press-07', 'type' => 'birth', 'signals' => [
        ['signal' => 'temp', 'data_type' => 'number'],
        ['signal' => 'spindle', 'data_type' => 'number', 'unit' => 'rpm'],
    ]]]]);

    runJob($message);

    $row = UnmappedSignal::query()->sole();
    expect($row->signal_key)->toBe('spindle')
        ->and($row->last_value)->toBeNull();
});

it('marks an unreadable payload failed with a reason and does not throw', function (): void {
    $ctx = pipelineSetup();
    $message = MachineMessage::factory()->create(['source_id' => $ctx['source']->id, 'payload' => 'not json {']);

    runJob($message);

    $message->refresh();
    expect($message->status)->toBe(MachineMessageStatus::Failed)
        ->and($message->error)->not->toBeEmpty()
        ->and(MachineIncident::query()->where('type', MachineIncidentType::MessageFailed->value)->count())->toBe(1);
});

it('marks a message failed and records one incident when processing throws', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [sampleRow('temp', 1)]);
    Event::fake([MachineStateObserved::class, PartsCounted::class, ProbeMeasured::class]);
    Event::listen(ProcessValuesSampled::class, static function (): void {
        throw new RuntimeException('listener broke');
    });

    $job = new ProcessMachineMessageJob($message->id, $message->source_id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'listener broke');
    $job->failed(new RuntimeException('listener broke'));

    $message->refresh();
    expect($message->status)->toBe(MachineMessageStatus::Failed)
        ->and($message->error)->toBe('listener broke')
        ->and(MachineIncident::query()->where('type', MachineIncidentType::MessageFailed->value)->count())->toBe(1);
});

it('leaves the same data after a second processing', function (): void {
    $ctx = pipelineSetup();
    $message = storeMessage($ctx['source'], [sampleRow('mystery', 5), sampleRow('state', 'HOLDING')]);

    runJob($message);
    $snapshot = [UnmappedSignal::query()->orderBy('id')->get()->toArray(), MachineIncident::query()->orderBy('id')->get()->toArray()];

    runJob($message->fresh(), reprocess: true);

    expect([UnmappedSignal::query()->orderBy('id')->get()->toArray(), MachineIncident::query()->orderBy('id')->get()->toArray()])->toEqual($snapshot)
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed);
});

it('serialises its processing per source and runs on the machine queue', function (): void {
    $job = new ProcessMachineMessageJob(1, 42);

    expect($job->queue)->toBe('mes-machine')
        ->and($job->tries)->toBe(3)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class);
});

it('a 5000-sample message uses the same number of queries as a 50-sample one', function (): void {
    $ctx = pipelineSetup();
    $build = static function (int $count) use ($ctx): MachineMessage {
        $samples = [];

        for ($i = 0; $i < $count; $i++) {
            $key = ['state', 'good_count', 'temp', 'ghost'][$i % 4];
            $samples[] = sampleRow($key, $key === 'state' ? 'EXECUTE' : $i, now()->subSeconds($count - $i)->toIso8601ZuluString('millisecond'));
        }

        return storeMessage($ctx['source'], $samples);
    };
    $small = $build(50);
    $large = $build(5000);
    runJob($build(4)); // warms the cached signal map, which the first message of a source reads

    DB::enableQueryLog();
    runJob($small);
    $small_queries = count(DB::getQueryLog());
    DB::flushQueryLog();
    runJob($large);
    $large_queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($large_queries)->toBe($small_queries);
});
