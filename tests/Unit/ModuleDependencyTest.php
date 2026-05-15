<?php

declare(strict_types=1);

/**
 * Validates: Requirements 12.1
 *
 * Verifies that the MES module correctly declares ERP as a required dependency
 * in both module.json (requires array) and composer.json (require section).
 */

test('module.json declares ERP as a required dependency', function (): void {
    $module_json_path = dirname(__DIR__, 2) . '/module.json';

    expect(file_exists($module_json_path))->toBeTrue('module.json must exist');

    $contents = file_get_contents($module_json_path);
    expect($contents)->not->toBeFalse();

    /** @var array{requires?: list<string>} $config */
    $config = json_decode((string) $contents, true);

    expect($config)
        ->toBeArray()
        ->toHaveKey('requires');

    expect($config['requires'])
        ->toBeArray()
        ->toContain('ERP');
});

test('composer.json declares ERP package as a required dependency', function (): void {
    $composer_json_path = dirname(__DIR__, 2) . '/composer.json';

    expect(file_exists($composer_json_path))->toBeTrue('composer.json must exist');

    $contents = file_get_contents($composer_json_path);
    expect($contents)->not->toBeFalse();

    /** @var array{require?: array<string, string>} $config */
    $config = json_decode((string) $contents, true);

    expect($config)
        ->toBeArray()
        ->toHaveKey('require');

    expect($config['require'])
        ->toBeArray()
        ->toHaveKey('swolley/laraplate-erp');
});
