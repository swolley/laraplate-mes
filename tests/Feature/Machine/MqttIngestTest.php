<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Machine\Mqtt\MqttIngest;
use Modules\MES\Machine\Mqtt\MqttMessage;
use Modules\MES\Machine\Mqtt\MqttMessageRouter;
use Modules\MES\Machine\Mqtt\MqttPayloadEnvelope;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Queue::fake();
    Cache::flush();
});

function mqttEnvelope(string $message_id = 'm-1'): string
{
    return json_encode([
        'protocol' => 'laraplate-machine/1',
        'message_id' => $message_id,
        'source_seq' => 1,
        'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'd1', 'type' => 'data', 'samples' => [['signal' => 's', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 1]]]],
    ], JSON_THROW_ON_ERROR);
}

it('stores a canonical envelope published on the default topic of its source and queues one job', function (): void {
    $source = MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);

    $result = resolve(MqttIngest::class)->handle(new MqttMessage('laraplate/laraplate-machine/1/gw-1', mqttEnvelope()));

    expect($result?->duplicate)->toBeFalse()
        ->and(MachineMessage::query()->sole()->transport)->toBe(MachineTransport::Mqtt)
        ->and(MachineMessage::query()->sole()->source_id)->toBe($source->id);
    Queue::assertPushed(Modules\MES\Jobs\ProcessMachineMessageJob::class, 1);
});

it('a redelivered message is stored once', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $ingest = resolve(MqttIngest::class);
    $message = new MqttMessage('laraplate/laraplate-machine/1/gw-1', mqttEnvelope());

    $first = $ingest->handle($message);
    $second = $ingest->handle($message);

    expect($first?->duplicate)->toBeFalse()
        ->and($second?->duplicate)->toBeTrue()
        ->and(MachineMessage::query()->count())->toBe(1);
    Queue::assertPushed(Modules\MES\Jobs\ProcessMachineMessageJob::class, 1);
});

it('drops a topic of no source, of an inactive source, or of two sources', function (): void {
    MachineSource::factory()->mqtt()->inactive()->create(['code' => 'off', 'mqtt_topic' => null]);
    MachineSource::factory()->mqtt()->create(['code' => 'a', 'mqtt_topic' => 'plant/shared/#']);
    // The model refuses overlapping topics, so the second is written around its rules: the router still has to cope with data that got in some other way.
    MachineSource::withoutEvents(static fn () => MachineSource::factory()->mqtt()->create(['code' => 'b', 'mqtt_topic' => 'plant/shared/+/x']));
    $ingest = resolve(MqttIngest::class);

    expect($ingest->handle(new MqttMessage('nobody/listens', mqttEnvelope())))->toBeNull()
        ->and($ingest->handle(new MqttMessage('laraplate/laraplate-machine/1/off', mqttEnvelope())))->toBeNull()
        ->and($ingest->handle(new MqttMessage('plant/shared/1/x', mqttEnvelope())))->toBeNull()
        ->and(MachineMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lists the topic of every active mqtt source once', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'a', 'mqtt_topic' => null]);
    MachineSource::factory()->mqtt()->create(['code' => 'b', 'mqtt_topic' => 'spBv1.0/plant/#', 'normalizer' => 'sparkplug_b']);
    MachineSource::factory()->mqtt()->inactive()->create(['code' => 'c', 'mqtt_topic' => null]);
    MachineSource::factory()->create(['code' => 'http-one']);

    expect(resolve(MqttMessageRouter::class)->subscriptions())->toEqualCanonicalizing(['laraplate/laraplate-machine/1/a', 'spBv1.0/plant/#']);
});

it('matches topics with the MQTT wildcards', function (string $filter, string $topic, bool $matches): void {
    MachineSource::factory()->mqtt()->create(['code' => 'w', 'mqtt_topic' => $filter, 'normalizer' => 'mapped_json']);

    expect(resolve(MqttMessageRouter::class)->sourceFor($topic) !== null)->toBe($matches);
})->with([
    'multi level' => ['spBv1.0/plant/#', 'spBv1.0/plant/DDATA/node/dev', true],
    'other group' => ['spBv1.0/plant/#', 'spBv1.0/other/NDATA/node', false],
    'single level' => ['a/+/c', 'a/b/c', true],
    'single level too deep' => ['a/+/c', 'a/b/x/c', false],
    'exact' => ['a/b', 'a/b', true],
    'exact longer' => ['a/b', 'a/b/c', false],
    'hash matches the parent' => ['a/#', 'a', true],
]);

it('drops an invalid envelope with one open incident naming the topic and the errors', function (): void {
    $source = MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $topic = 'laraplate/laraplate-machine/1/gw-1';
    $ingest = resolve(MqttIngest::class);

    expect($ingest->handle(new MqttMessage($topic, '{"protocol":"laraplate-machine/9"}')))->toBeNull()
        ->and($ingest->handle(new MqttMessage($topic, 'not json')))->toBeNull()
        ->and(MachineMessage::query()->count())->toBe(0);

    $incident = MachineIncident::query()->where('source_id', $source->id)->sole();
    expect($incident->type)->toBe(MachineIncidentType::MessageFailed)
        ->and($incident->detail['topic'])->toBe($topic)
        ->and($incident->detail)->toHaveKey('errors');
});

it('wraps a sparkplug payload with its topic and gives the bytes back untouched', function (): void {
    // The sparkplug_b normaliser arrives in a later task; this test is about what the ingest stores.
    resolve(Modules\MES\Machine\Normalizers\NormalizerRegistry::class)->register(new Modules\MES\Tests\Support\StubNormalizer('sparkplug_b'));
    $source = MachineSource::factory()->mqtt()->create(['code' => 'sp', 'mqtt_topic' => 'spBv1.0/plant/#', 'normalizer' => 'sparkplug_b']);
    $bytes = "\x08\xff\x00\xfe\x80binary";
    $topic = 'spBv1.0/plant/DDATA/node/dev';

    resolve(MqttIngest::class)->handle(new MqttMessage($topic, $bytes));

    $stored = MachineMessage::query()->where('source_id', $source->id)->sole()->payload;
    expect(MqttPayloadEnvelope::unwrap($stored))->toBe(['topic' => $topic, 'payload' => $bytes])
        ->and(json_decode($stored, true))->toHaveKeys(['topic', 'payload_base64']);
});

it('stores a mapped_json payload as received', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'nr', 'mqtt_topic' => 'plant/nodered', 'normalizer' => 'mapped_json', 'normalizer_options' => ['device' => 'd', 'signal' => 's', 'timestamp' => 't', 'value' => 'v']]);

    resolve(MqttIngest::class)->handle(new MqttMessage('plant/nodered', '{"d":"a","s":"b","t":"2026-10-05T08:00:00Z","v":1}'));

    expect(MachineMessage::query()->sole()->payload)->toBe('{"d":"a","s":"b","t":"2026-10-05T08:00:00Z","v":1}');
});

it('refuses a topic filter that is not valid MQTT', function (string $filter): void {
    expect(fn () => MachineSource::factory()->mqtt()->create(['normalizer' => 'mapped_json', 'mqtt_topic' => $filter]))->toThrow(ValidationException::class);
})->with(['a/#/b', 'a+', 'a/b#', 'a/+b', '#a']);

it('accepts valid topic filters', function (string $filter): void {
    expect(MachineSource::factory()->mqtt()->create(['normalizer' => 'mapped_json', 'mqtt_topic' => $filter, 'code' => 'v' . md5($filter)])->exists)->toBeTrue();
})->with(['a/+/c', 'a/#', 'spBv1.0/plant/#', 'plant/nodered', '+/status']);

it('refuses a topic that overlaps the topic of another source, in any company and whatever its state', function (): void {
    MachineSource::factory()->mqtt()->inactive()->create(['code' => 'a', 'normalizer' => 'mapped_json', 'mqtt_topic' => 'plant/shared/#']);
    $other_company = MesTestHelpers::makeCompany();

    expect(fn () => MachineSource::factory()->mqtt()->create(['company_id' => $other_company->id, 'code' => 'b', 'normalizer' => 'mapped_json', 'mqtt_topic' => 'plant/+/x']))->toThrow(ValidationException::class);
    expect(MachineSource::factory()->mqtt()->create(['company_id' => $other_company->id, 'code' => 'c', 'normalizer' => 'mapped_json', 'mqtt_topic' => 'plant/other/x'])->exists)->toBeTrue();
});

it('refuses two canonical sources with the same code in different companies, which would share a topic', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $other_company = MesTestHelpers::makeCompany();

    expect(fn () => MachineSource::factory()->mqtt()->create(['company_id' => $other_company->id, 'code' => 'gw-1', 'mqtt_topic' => null]))->toThrow(ValidationException::class);
});

it('lets a source be edited without clashing with itself', function (): void {
    $source = MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);

    $source->update(['name' => 'Renamed']);

    expect($source->fresh()->name)->toBe('Renamed');
});

it('overlaps topic filters level by level', function (string $a, string $b, bool $overlap): void {
    expect(MqttMessageRouter::overlaps($a, $b))->toBe($overlap)
        ->and(MqttMessageRouter::overlaps($b, $a))->toBe($overlap);
})->with([
    'same' => ['a/b', 'a/b', true],
    'hash and a child' => ['a/#', 'a/b/c', true],
    'hash and its parent' => ['a/#', 'a', true],
    'plus and a name' => ['a/+/c', 'a/b/c', true],
    'plus and a hash' => ['a/+/c', 'a/#', true],
    'different names' => ['a/b', 'a/c', false],
    'different depth' => ['a/b', 'a/b/c', false],
    'plus at another depth' => ['a/+', 'a/b/c', false],
    'two hashes apart' => ['a/#', 'b/#', false],
]);

it('requires a topic for a non-canonical mqtt source', function (): void {
    expect(fn () => MachineSource::factory()->mqtt()->create(['normalizer' => 'sparkplug_b', 'mqtt_topic' => null]))->toThrow(ValidationException::class);
    expect(MachineSource::factory()->mqtt()->create(['normalizer' => 'canonical', 'mqtt_topic' => null])->exists)->toBeTrue();
});
