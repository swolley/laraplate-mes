<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Machine\Data\DeviceNotice;
use Modules\MES\Machine\Data\NormalizedMessage;
use Modules\MES\Machine\MachineMessageInbox;
use Modules\MES\Machine\MachineMessageProcessor;
use Modules\MES\Machine\Mqtt\MqttPayloadEnvelope;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\SparkplugAlias;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

function sparkplugSource(): MachineSource
{
    return MachineSource::factory()->mqtt()->create(['code' => 'sp', 'normalizer' => 'sparkplug_b', 'mqtt_topic' => 'spBv1.0/plant/#']);
}

function sparkplugBytes(string $fixture): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . "/Fixtures/machine-protocol/normalizers/sparkplug_b/{$fixture}.payload.bin");
}

function sparkplugStored(string $topic, string $fixture, ?int $cut = null): string
{
    $bytes = sparkplugBytes($fixture);

    return MqttPayloadEnvelope::wrap($topic, $cut === null ? $bytes : substr($bytes, 0, $cut));
}

function normalizeSparkplug(MachineSource $source, string $topic, string $fixture): NormalizedMessage
{
    return resolve(NormalizerRegistry::class)->for($source)->normalize($source, sparkplugStored($topic, $fixture));
}

/**
 * @return list<array{string, string, int|float|bool|string}>
 */
function sampleSummary(NormalizedMessage $message): array
{
    return array_map(static fn ($sample): array => [$sample->device, $sample->signal, $sample->value], $message->samples);
}

const SPARKPLUG_T0 = '2026-09-21T14:13:20.000Z';

it('turns a node birth into a birth notice, its named values and its aliases, leaving the control metrics out', function (): void {
    $source = sparkplugSource();

    $message = normalizeSparkplug($source, 'spBv1.0/plant/NBIRTH/node1', 'birth');

    expect(sampleSummary($message))->toBe([['node1', 'temp', 21.5], ['node1', 'count', 42], ['node1', 'running', true], ['node1', 'mode', 'AUTO']])
        ->and($message->samples[0]->ts->utc()->format('Y-m-d\TH:i:s.v\Z'))->toBe(SPARKPLUG_T0)
        ->and($message->notices)->toHaveCount(1)
        ->and($message->notices[0]->type)->toBe(DeviceNotice::BIRTH)
        ->and($message->notices[0]->device)->toBe('node1')
        ->and(array_column($message->notices[0]->signals, 'data_type', 'signal'))->toBe(['temp' => 'number', 'count' => 'number', 'running' => 'boolean', 'mode' => 'string'])
        ->and(SparkplugAlias::query()->where('device_external_id', 'node1')->pluck('name', 'alias')->all())->toBe([1 => 'temp', 2 => 'count', 3 => 'running', 4 => 'mode']);
});

it('names the metrics of a device data message from the aliases of its birth', function (): void {
    $source = sparkplugSource();
    normalizeSparkplug($source, 'spBv1.0/plant/DBIRTH/node1/dev2', 'birth');

    $message = normalizeSparkplug($source, 'spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only');

    expect(sampleSummary($message))->toBe([['node1/dev2', 'temp', 22.5], ['node1/dev2', 'count', 43]])
        ->and($message->samples[0]->ts->utc()->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-09-21T14:13:25.000Z')
        ->and($message->notices)->toBe([]);
});

it('keeps data that arrives before its birth as alias signals, and names it once the birth is known', function (): void {
    $source = sparkplugSource();

    $early = normalizeSparkplug($source, 'spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only');
    expect(sampleSummary($early))->toBe([['node1/dev2', 'alias#1', 22.5], ['node1/dev2', 'alias#2', 43]]);

    normalizeSparkplug($source, 'spBv1.0/plant/DBIRTH/node1/dev2', 'birth');
    $late = normalizeSparkplug($source, 'spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only');

    expect(sampleSummary($late))->toBe([['node1/dev2', 'temp', 22.5], ['node1/dev2', 'count', 43]]);
});

it('reads metrics that carry their names, with signed integers restored', function (): void {
    $message = normalizeSparkplug(sparkplugSource(), 'spBv1.0/plant/DDATA/node1/dev2', 'signed-ints');

    expect(sampleSummary($message))->toBe([
        ['node1/dev2', 'i8', -5], ['node1/dev2', 'i16', -300], ['node1/dev2', 'i32', -70000], ['node1/dev2', 'i64', -5000000000],
        ['node1/dev2', 'u8', 200], ['node1/dev2', 'u32', 4000000000], ['node1/dev2', 'u64', '18446744073709551615'],
    ]);
});

it('skips null metrics and datatypes without a scalar value, and keeps historical ones', function (): void {
    $message = normalizeSparkplug(sparkplugSource(), 'spBv1.0/plant/DDATA/node1/dev2', 'unsupported-and-null');

    expect(sampleSummary($message))->toBe([['node1/dev2', 'history', 7], ['node1/dev2', 'ratio', 1.5], ['node1/dev2', 'stamp', 1789999999000], ['node1/dev2', 'note', 'hello'], ['node1/dev2', 'flag', false]]);
});

it('turns a node death into deaths of the node and of the devices configured under it', function (): void {
    $source = sparkplugSource();
    foreach (['node1/dev2', 'node1/dev3', 'other/dev9'] as $external_id) {
        MachineDevice::factory()->create(['source_id' => $source->id, 'external_id' => $external_id]);
    }

    $message = normalizeSparkplug($source, 'spBv1.0/plant/NDEATH/node1', 'birth');

    expect(collect($message->notices)->pluck('device')->sort()->values()->all())->toBe(['node1', 'node1/dev2', 'node1/dev3'])
        ->and(collect($message->notices)->pluck('type')->unique()->all())->toBe([DeviceNotice::DEATH])
        ->and($message->samples)->toBe([]);
});

it('turns a device death into one death notice', function (): void {
    $message = normalizeSparkplug(sparkplugSource(), 'spBv1.0/plant/DDEATH/node1/dev2', 'birth');

    expect(collect($message->notices)->pluck('device')->all())->toBe(['node1/dev2']);
});

it('ignores command and state topics', function (string $topic): void {
    $source = sparkplugSource();
    $normalizer = resolve(NormalizerRegistry::class)->for($source);
    $stored = sparkplugStored($topic, 'birth');

    expect($normalizer->normalize($source, $stored)->samples)->toBe([])
        ->and($normalizer->normalize($source, $stored)->notices)->toBe([])
        ->and($normalizer->meta($source, $stored)->message_id)->not->toBe('');
})->with(['spBv1.0/plant/NCMD/node1', 'spBv1.0/plant/DCMD/node1/dev2', 'spBv1.0/plant/STATE/host']);

it('derives a stable message id from the topic, the sequence and the time, and no source sequence', function (): void {
    $source = sparkplugSource();
    $normalizer = resolve(NormalizerRegistry::class)->for($source);
    $a = $normalizer->meta($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only'));
    $b = $normalizer->meta($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only'));
    $other_time = $normalizer->meta($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'signed-ints'));
    $other_topic = $normalizer->meta($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev3', 'data-alias-only'));

    expect($a->message_id)->toBe('sparkplug:spBv1.0/plant/DDATA/node1/dev2:1:1790000005000')
        ->and($a->message_id)->toBe($b->message_id)
        ->and($other_time->message_id)->not->toBe($a->message_id)
        ->and($other_topic->message_id)->not->toBe($a->message_id)
        ->and($a->source_seq)->toBeNull()
        ->and($a->sent_at?->utc()->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-09-21T14:13:25.000Z');
});

it('sees no gap when the Sparkplug sequence wraps from 255 to 0', function (): void {
    Queue::fake();
    $source = sparkplugSource();
    $inbox = resolve(MachineMessageInbox::class);

    $inbox->accept($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'seq-255'), MachineTransport::Mqtt);
    $inbox->accept($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'seq-0'), MachineTransport::Mqtt);

    expect(MachineMessage::query()->count())->toBe(2)
        ->and(MachineIncident::query()->where('type', Modules\MES\Enums\MachineIncidentType::SeqGap->value)->count())->toBe(0);
});

it('refuses a cut payload, which the job turns into a failed, reprocessable message', function (): void {
    Queue::fake();
    $source = sparkplugSource();
    $stored = sparkplugStored('spBv1.0/plant/NBIRTH/node1', 'birth', 20);

    expect(fn () => resolve(NormalizerRegistry::class)->for($source)->normalize($source, $stored))->toThrow(UnreadableMachinePayload::class);

    $message = resolve(MachineMessageInbox::class)->accept($source, $stored, MachineTransport::Mqtt)->message;
    app()->call([new ProcessMachineMessageJob($message->id, $source->id), 'handle']);

    expect($message->fresh()->status)->toBe(MachineMessageStatus::Failed)
        ->and($message->fresh()->error)->not->toBeEmpty();
});

it('runs through the whole pipeline: the birth declares the signals, the data follows by alias', function (): void {
    $source = sparkplugSource();
    $device = MachineDevice::factory()->create(['source_id' => $source->id, 'external_id' => 'node1/dev2']);
    Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'temp', 'role' => 'process_value', 'config' => null]);
    Illuminate\Support\Facades\Event::fake([Modules\MES\Events\ProcessValuesSampled::class]);
    $processor = resolve(MachineMessageProcessor::class);
    $normalizer = resolve(NormalizerRegistry::class)->for($source);

    $processor->process($source, $normalizer->normalize($source, sparkplugStored('spBv1.0/plant/DBIRTH/node1/dev2', 'birth')), now(), true);
    $processor->process($source, $normalizer->normalize($source, sparkplugStored('spBv1.0/plant/DDATA/node1/dev2', 'data-alias-only')), now(), true);

    Illuminate\Support\Facades\Event::assertDispatched(Modules\MES\Events\ProcessValuesSampled::class, static fn ($event): bool => $event->samples[0]->sample->value === 22.5);
});

it('ships the sparkplug_b normaliser by default', function (): void {
    expect(resolve(NormalizerRegistry::class)->keys())->toContain('sparkplug_b');
});
