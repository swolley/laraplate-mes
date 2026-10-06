<?php

declare(strict_types=1);

$module = dirname(__DIR__, 3);

it('documents every machine configuration key and env variable in the README', function () use ($module): void {
    $readme = (string) file_get_contents("{$module}/README.md");
    $config = (string) file_get_contents("{$module}/config/config.php");

    preg_match_all('/MES_MACHINE_[A-Z_]+/', $config, $matches);
    $env_names = array_unique($matches[0]);

    expect($env_names)->toHaveCount(6);

    foreach ($env_names as $env_name) {
        expect($readme)->toContain($env_name);
    }

    foreach (['queue', 'max_samples', 'max_body_kb', 'clock_skew_seconds', 'inbox_retention_days', 'rate_limit_per_minute'] as $key) {
        expect($readme)->toContain("mes.machine.{$key}");
    }
});

it('documents the protocol and the HTTP contract', function () use ($module): void {
    $document = (string) file_get_contents("{$module}/docs/MACHINE_CONNECTIVITY.md");

    foreach (['laraplate-machine/1', 'api/v1/mes/machine-data', 'mes:machine-ingest', '202', '200', '401', '403', '413', '422', '429'] as $needle) {
        expect($document)->toContain($needle);
    }
});

it('publishes a schema that decodes', function () use ($module): void {
    $schema = json_decode((string) file_get_contents("{$module}/resources/protocol/laraplate-machine-1.schema.json"), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['properties']['protocol']['const'])->toBe('laraplate-machine/1');
});
