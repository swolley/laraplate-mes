<?php

declare(strict_types=1);

use Modules\MES\Machine\Protocol\MachineEnvelopeValidator;

/**
 * @return array<string, array{string}>
 */
function protocolFixtures(string $directory): array
{
    $files = glob(dirname(__DIR__, 2) . "/Fixtures/machine-protocol/{$directory}/*.json") ?: [];
    $cases = [];

    foreach ($files as $file) {
        $cases[basename($file, '.json')] = [$file];
    }

    return $cases;
}

function loadEnvelope(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

it('accepts every valid envelope fixture', function (string $file): void {
    expect(resolve(MachineEnvelopeValidator::class)->validate(loadEnvelope($file)))->toBe([]);
})->with(protocolFixtures('valid'));

it('rejects every invalid envelope fixture', function (string $file): void {
    expect(resolve(MachineEnvelopeValidator::class)->validate(loadEnvelope($file)))->not->toBeEmpty();
})->with(protocolFixtures('invalid'));

it('ships the fixtures it is tested with', function (): void {
    expect(protocolFixtures('valid'))->toHaveCount(3)
        ->and(protocolFixtures('invalid'))->toHaveCount(13);
});

it('counts the samples of an envelope', function (): void {
    $envelope = loadEnvelope(dirname(__DIR__, 2) . '/Fixtures/machine-protocol/valid/with-context.json');

    expect(resolve(MachineEnvelopeValidator::class)->sampleCount($envelope))->toBe(3);
});

it('publishes the schema of the protocol', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/protocol/laraplate-machine-1.schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['$id'])->toBe('https://laraplate.dev/schemas/laraplate-machine-1.schema.json')
        ->and($schema['properties']['protocol']['const'])->toBe('laraplate-machine/1');
});
