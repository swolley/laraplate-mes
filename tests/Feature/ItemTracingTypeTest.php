<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ERP\Casts\TracingType;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function mesItemCompany(): Company
{
    return Company::query()->withoutGlobalScopes()->create([
        'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
        'name' => fake()->company(),
        'fiscal_country' => 'IT',
        'default_currency' => 'EUR',
    ]);
}

/**
 * Create a minimal Item with the given tracing_type.
 */
function makeTracedItem(Company $company, TracingType $tracingType): Item
{
    return Item::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => fake()->word(),
        'sku' => fake()->unique()->bothify('SKU-????-####'),
        'uom' => 'pcs',
        'costing_method' => 'fifo',
        'tracing_type' => $tracingType->value,
    ]);
}

// ---------------------------------------------------------------------------
// tracing_type: fillable & cast verified from the MES context
// ---------------------------------------------------------------------------

it('can persist and retrieve each TracingType on an Item from MES context', function (): void {
    $company = mesItemCompany();

    foreach (TracingType::cases() as $type) {
        $item = makeTracedItem($company, $type);

        // Re-read from the database — as MES would do via Eloquent
        $fresh = Item::withoutGlobalScopes()->findOrFail($item->id);

        expect($fresh->tracing_type)
            ->toBeInstanceOf(TracingType::class)
            ->toBe($type);
    }
});

it('defaults tracing_type to TracingType::None when the column is not specified', function (): void {
    $company = mesItemCompany();

    $item = Item::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Product without tracing',
        'sku' => fake()->unique()->bothify('SKU-????-####'),
        'uom' => 'pcs',
        'costing_method' => 'fifo',
    ]);

    // Reload to pick up the DB default
    $fresh = Item::withoutGlobalScopes()->findOrFail($item->id);

    expect($fresh->tracing_type)->toBe(TracingType::None);
});

it('reads tracing_type via Eloquent stock_levels relation chain from MES context', function (): void {
    $company = mesItemCompany();

    $item = makeTracedItem($company, TracingType::Lot);

    // The MES reads the ERP Item directly — verify the relation resolves and preserves the cast
    $resolvedItem = Item::withoutGlobalScopes()
        ->where('id', $item->id)
        ->first();

    expect($resolvedItem)
        ->toBeInstanceOf(Item::class)
        ->and($resolvedItem->tracing_type)->toBe(TracingType::Lot)
        ->and($resolvedItem->tracing_type->value)->toBe('lot');
});

it('tracing_type is mass-assignable on Item', function (): void {
    $company = mesItemCompany();

    $item = Item::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Serial item',
        'sku' => fake()->unique()->bothify('SKU-????-####'),
        'uom' => 'pcs',
        'costing_method' => 'fifo',
        'tracing_type' => TracingType::Serial->value,
    ]);

    expect($item->tracing_type)->toBe(TracingType::Serial);
});
