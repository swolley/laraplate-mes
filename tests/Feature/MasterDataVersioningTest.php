<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Setting;
use Modules\Core\Models\Version;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Overtrue\LaravelVersionable\VersionStrategy;

uses(RefreshDatabase::class);

dataset('versioned models', [
    'bom' => [Bom::class, fn () => Bom::factory()->create(['version' => '1.0']), 'version', '1.1'],
    'routing' => [Routing::class, fn () => Routing::factory()->create(['version' => '1.0']), 'version', '1.1'],
    'production order' => [ProductionOrder::class, fn () => ProductionOrder::factory()->create(['quantity_planned' => 10]), 'quantity_planned', 12],
]);

it('seeds a DIFF version strategy setting for the model (D9)', function (string $class): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);

    $setting = Setting::query()->where('name', 'versioning.strategy.' . (new $class())->getTable())->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe(VersionStrategy::DIFF->value);
})->with([Bom::class, Routing::class, ProductionOrder::class]);

it('records what changed on every save once seeded', function (string $class, Closure $make, string $attribute, string|int $new_value): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);
    app(PerModelSettingResolver::class)->flush();
    $class::resetVersionStrategyCache();

    $model = $make();
    $before = $model->versions()->count();

    $model->update([$attribute => $new_value]);

    $versions = $model->versions()->get();
    expect($versions)->toHaveCount($before + 1)
        ->and($versions->last()->contents)->toHaveKey($attribute);
})->with('versioned models');

dataset('versioned lines', [
    'bom line' => [BomLine::class, fn () => BomLine::factory()->create(['quantity' => 2]), 'quantity', 5],
    'routing operation' => [RoutingOperation::class, fn () => RoutingOperation::factory()->create(['description' => 'Cut']), 'description', 'Cut and deburr'],
]);

it('records edits to lines and operations, which carry the substance of a bom or routing', function (string $class, Closure $make, string $attribute, string|int $new_value): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);
    app(PerModelSettingResolver::class)->flush();
    $class::resetVersionStrategyCache();

    $model = $make();
    $before = $model->versions()->count();

    $model->update([$attribute => $new_value]);

    expect($model->versions()->count())->toBe($before + 1);
})->with('versioned lines');

it('keeps the whole image of a line or operation that is deleted for good', function (string $class, Closure $make, string $attribute): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);
    app(PerModelSettingResolver::class)->flush();
    $class::resetVersionStrategyCache();

    $model = $make();
    $id = $model->getKey();
    $original = $model->getAttribute($attribute);

    $model->delete();

    expect($class::query()->whereKey($id)->exists())->toBeFalse();

    $kept = Version::query()->where('versionable_type', $model->getMorphClass())->where('versionable_id', $id)->get();

    $image = $kept->pluck('contents')->first(static fn (mixed $contents): bool => is_array($contents) && ($contents['id'] ?? null) === $id);

    expect($image)->not->toBeNull()
        ->and($image[$attribute])->toEqual($original);
})->with([
    'bom line' => [BomLine::class, fn () => BomLine::factory()->create(['quantity' => 7]), 'quantity'],
    'routing operation' => [RoutingOperation::class, fn () => RoutingOperation::factory()->create(['description' => 'Weld seam']), 'description'],
]);
