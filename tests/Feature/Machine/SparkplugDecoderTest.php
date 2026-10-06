<?php

declare(strict_types=1);

use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Machine\Sparkplug\ProtobufReader;
use Modules\MES\Machine\Sparkplug\SparkplugMetric;
use Modules\MES\Machine\Sparkplug\SparkplugPayload;
use Modules\MES\Machine\Sparkplug\SparkplugPayloadDecoder;
use Modules\MES\Machine\Sparkplug\SparkplugTopic;

/**
 * @return array<string, array{string}>
 */
function sparkplugBinaries(): array
{
    $cases = [];

    foreach (glob(dirname(__DIR__, 2) . '/Fixtures/machine-protocol/normalizers/sparkplug_b/*.payload.bin') ?: [] as $file) {
        $cases[basename($file, '.payload.bin')] = [substr($file, 0, -strlen('.payload.bin'))];
    }

    return $cases;
}

/**
 * @return array<string, mixed>
 */
function describePayload(SparkplugPayload $payload): array
{
    return [
        'timestamp_ms' => $payload->timestamp_ms,
        'seq' => $payload->seq,
        'metrics' => array_map(static fn (SparkplugMetric $metric): array => [
            'name' => $metric->name,
            'alias' => $metric->alias,
            'timestamp_ms' => $metric->timestamp_ms,
            'datatype' => $metric->datatype,
            'is_null' => $metric->is_null,
            'is_historical' => $metric->is_historical,
            'value' => $metric->value,
            'supported' => $metric->supported,
        ], $payload->metrics),
    ];
}

it('ships the four fixtures, encoded by protoc from the Eclipse Tahu schema', function (): void {
    expect(array_keys(sparkplugBinaries()))->toBe(['birth', 'data-alias-only', 'signed-ints', 'unsupported-and-null']);
});

it('decodes every fixture to its expected structure', function (string $base): void {
    $decoded = new SparkplugPayloadDecoder()->decode((string) file_get_contents("{$base}.payload.bin"));

    expect(describePayload($decoded))->toEqual(json_decode((string) file_get_contents("{$base}.decoded.json"), true, 512, JSON_THROW_ON_ERROR));
})->with(sparkplugBinaries());

it('only ever reports a cut payload as unreadable', function (string $base): void {
    $bytes = (string) file_get_contents("{$base}.payload.bin");
    $decoder = new SparkplugPayloadDecoder();

    for ($length = 0; $length < strlen($bytes); $length++) {
        try {
            expect($decoder->decode(substr($bytes, 0, $length)))->toBeInstanceOf(SparkplugPayload::class);
        } catch (UnreadableMachinePayload) {
            // the allowed way to refuse
        }
    }
})->with(sparkplugBinaries());

it('only ever reports random bytes as unreadable', function (): void {
    $decoder = new SparkplugPayloadDecoder();
    mt_srand(42);

    for ($i = 0; $i < 300; $i++) {
        $bytes = '';

        for ($n = mt_rand(1, 64); $n > 0; $n--) {
            $bytes .= chr(mt_rand(0, 255));
        }

        try {
            expect($decoder->decode($bytes))->toBeInstanceOf(SparkplugPayload::class);
        } catch (UnreadableMachinePayload) {
            // the allowed way to refuse
        }
    }
});

it('reads varints at their boundaries', function (string $bytes, int $expected): void {
    expect(new ProtobufReader($bytes)->readVarint())->toBe($expected);
})->with([
    'one byte' => ["\x7f", 127],
    'two bytes' => ["\x80\x01", 128],
    'three bytes' => ["\x80\x80\x01", 16384],
    'ten bytes, the top bit set' => ["\xff\xff\xff\xff\xff\xff\xff\xff\xff\x01", -1],
]);

it('refuses a truncated or overlong varint, a length past the end, and an unknown wire type', function (string $bytes, string $read): void {
    $reader = new ProtobufReader($bytes);

    expect(fn () => match ($read) {
        'varint' => $reader->readVarint(),
        'length' => $reader->readLengthDelimited(),
        default => $reader->skip(7),
    })->toThrow(UnreadableMachinePayload::class);
})->with([
    'truncated varint' => ["\x80", 'varint'],
    'eleven bytes' => ["\x80\x80\x80\x80\x80\x80\x80\x80\x80\x80\x01", 'varint'],
    'length past the end' => ["\x05ab", 'length'],
    'unknown wire type' => ['', 'wire'],
]);

it('parses Sparkplug topics', function (): void {
    $data = SparkplugTopic::parse('spBv1.0/plant/DDATA/node1/dev2');
    $birth = SparkplugTopic::parse('spBv1.0/plant/NBIRTH/node1');

    expect($data?->group)->toBe('plant')
        ->and($data?->type)->toBe('DDATA')
        ->and($data?->edge_node)->toBe('node1')
        ->and($data?->device)->toBe('dev2')
        ->and($data?->deviceExternalId())->toBe('node1/dev2')
        ->and($birth?->deviceExternalId())->toBe('node1')
        ->and(SparkplugTopic::parse('spBv1.0/plant/STATE/host'))->toBeNull()
        ->and(SparkplugTopic::parse('other/plant/NDATA/node'))->toBeNull()
        ->and(SparkplugTopic::parse('spBv1.0/plant/NDATA/node/dev'))->toBeNull()
        ->and(SparkplugTopic::parse('spBv1.0/plant/DDATA/node'))->toBeNull();
});
