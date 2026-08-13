<?php

declare(strict_types=1);

namespace Modules\MES\Tests\Support;

use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\Warehouse;

/**
 * Shared setup helpers for MES tests.
 *
 * Kept as a static helper class (not global Pest functions) so that multiple
 * test files can reuse the same factories without redeclaration conflicts.
 */
final class MesTestHelpers
{
    public static function makeCompany(): Company
    {
        return Company::query()->withoutGlobalScopes()->create([
            'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
            'name' => fake()->company(),
            'fiscal_country' => 'IT',
            'default_currency' => 'EUR',
        ]);
    }

    public static function makeItem(int $company_id): Item
    {
        return Item::query()->withoutGlobalScopes()->create([
            'company_id' => $company_id,
            'name' => fake()->words(3, true),
            'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####')),
            'uom' => 'pcs',
            'costing_method' => 'fifo',
        ]);
    }

    public static function makeWarehouse(int $company_id): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $company_id,
            'code' => mb_strtoupper(fake()->unique()->bothify('WH-##')),
            'name' => fake()->words(2, true),
        ]);
    }
}
