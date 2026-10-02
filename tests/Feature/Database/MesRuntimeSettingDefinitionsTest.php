<?php

declare(strict_types=1);

use Modules\MES\Database\Seeders\MESDatabaseSeeder;

it('defines mes runtime settings with current defaults', function (): void {
    $definitions = collect(MESDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions->get('lots.number_format')['value'])->toBe('{YEAR}{MONTH}{DAY}-{SEQ}');
});
