<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Setting;
use Modules\MES\Database\Seeders\MESDatabaseSeeder;

uses(RefreshDatabase::class);

it('seeds MES runtime settings stamped with the MES module', function (): void {
    $this->seed(MESDatabaseSeeder::class);

    $names = collect(MESDatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    $settings = Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get();

    expect($settings)->toHaveCount($names->count())
        ->and($settings->pluck('module')->unique()->all())->toBe(['MES']);
});

it('is idempotent and leaves an operator-changed value untouched on a second run', function (): void {
    $this->seed(MESDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()
        ->where('name', 'mes.rate_limit')
        ->update(['value' => json_encode(999), 'description' => 'drifted']);

    $this->seed(MESDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', 'mes.rate_limit')->sole();

    expect($setting->value)->toBe(999)
        ->and($setting->description)->toBe('Maximum MES API requests per minute');
});
