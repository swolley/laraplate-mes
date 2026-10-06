<?php

declare(strict_types=1);

use Modules\MES\Machine\Data\MessageMeta;
use Modules\MES\Machine\Normalizers\CanonicalNormalizer;
use Modules\MES\Machine\Normalizers\MappedJsonNormalizer;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Modules\MES\Machine\Normalizers\UnreadableMachinePayload;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\StubNormalizer;

/**
 * @return array<string, array{string, string}> fixture name => [normaliser key, base path without suffix]
 */
function normalizerFixtures(): array
{
    $cases = [];

    foreach (['canonical', 'mapped_json'] as $key) {
        foreach (glob(dirname(__DIR__, 2) . "/Fixtures/machine-protocol/normalizers/{$key}/*.input.json") ?: [] as $input) {
            $base = substr($input, 0, -strlen('.input.json'));
            $cases["{$key}/" . basename($base)] = [$key, $base];
        }
    }

    return $cases;
}

function fixtureJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array{samples: list<array<string, mixed>>, notices: list<array<string, mixed>>}
 */
function describeNormalized(Modules\MES\Machine\Data\NormalizedMessage $message): array
{
    return [
        'samples' => array_map(static fn ($sample): array => [
            'device' => $sample->device,
            'signal' => $sample->signal,
            'ts' => $sample->ts->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'value' => $sample->value,
            'quality' => $sample->quality->value,
            'context' => $sample->context,
        ], $message->samples),
        'notices' => array_map(static fn ($notice): array => [
            'device' => $notice->device,
            'type' => $notice->type,
            'ts' => $notice->ts->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'signals' => $notice->signals,
        ], $message->notices),
    ];
}

it('normalises every fixture to its expected samples and notices', function (string $key, string $base): void {
    $options = is_file("{$base}.options.json") ? fixtureJson("{$base}.options.json") : null;
    $source = new MachineSource(['normalizer' => $key, 'normalizer_options' => $options]);
    $normalizer = resolve(NormalizerRegistry::class)->for($source);

    $normalized = $normalizer->normalize($source, (string) file_get_contents("{$base}.input.json"));

    expect(describeNormalized($normalized))->toEqual(fixtureJson("{$base}.expected.json"));
})->with(normalizerFixtures());

it('throws on a payload it cannot read', function (string $normalizer): void {
    $source = new MachineSource(['normalizer' => $normalizer, 'normalizer_options' => ['device' => 'd', 'signal' => 's', 'timestamp' => 't', 'value' => 'v']]);
    $instance = resolve(NormalizerRegistry::class)->for($source);

    expect(fn () => $instance->normalize($source, 'not json {'))->toThrow(UnreadableMachinePayload::class)
        ->and(fn () => $instance->meta($source, 'not json {'))->toThrow(UnreadableMachinePayload::class);
})->with(['canonical', 'mapped_json']);

it('reads the metadata of a canonical envelope', function (): void {
    $source = new MachineSource(['normalizer' => 'canonical']);
    $payload = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/machine-protocol/valid/with-context.json');

    $meta = new CanonicalNormalizer()->meta($source, $payload);

    expect($meta)->toBeInstanceOf(MessageMeta::class)
        ->and($meta->message_id)->toBe('0192f1c4-7a2e-7c1b-9f00-3b2d5e6a7c10')
        ->and($meta->source_seq)->toBe(1842)
        ->and($meta->sent_at?->utc()->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-10-05T08:15:02.120Z');
});

it('derives the message id of a mapped payload from its content, or from the configured path', function (): void {
    $plain = new MachineSource(['normalizer' => 'mapped_json', 'normalizer_options' => []]);
    $with_path = new MachineSource(['normalizer' => 'mapped_json', 'normalizer_options' => ['message_id_path' => 'meta.id']]);
    $normalizer = new MappedJsonNormalizer();
    $payload = '{"a":1}';

    expect($normalizer->meta($plain, $payload)->message_id)->toBe(sha1($payload))
        ->and($normalizer->meta($plain, '{"a":2}')->message_id)->not->toBe(sha1($payload))
        ->and($normalizer->meta($plain, $payload)->source_seq)->toBeNull()
        ->and($normalizer->meta($with_path, '{"meta":{"id":"abc"}}')->message_id)->toBe('abc');
});

it('refuses an unknown normaliser key and serves a registered one', function (): void {
    $registry = new NormalizerRegistry();

    expect(fn () => $registry->for(new MachineSource(['normalizer' => 'nope'])))->toThrow(InvalidArgumentException::class, 'nope');

    $registry->register(new StubNormalizer('stub'));

    expect($registry->for(new MachineSource(['normalizer' => 'stub']))->key())->toBe('stub')
        ->and($registry->keys())->toBe(['stub']);
});

it('ships the canonical and mapped_json normalisers by default', function (): void {
    expect(resolve(NormalizerRegistry::class)->keys())->toContain('canonical', 'mapped_json');
});
