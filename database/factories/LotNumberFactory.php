<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\MES\Models\LotNumber;
use Override;

/**
 * @extends Factory<LotNumber>
 */
final class LotNumberFactory extends Factory
{
    /**
     * @var class-string<LotNumber>
     */
    protected $model = LotNumber::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $company_id = Company::query()->withoutGlobalScopes()->first()?->id
            ?? Company::query()->withoutGlobalScopes()->create([
                'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
                'name' => fake()->company(),
                'fiscal_country' => 'IT',
                'default_currency' => 'EUR',
            ])->id;

        return [
            'company_id' => $company_id,
            'item_id' => Item::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'name' => fake()->words(3, true),
                'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####')),
                'uom' => 'pcs',
                'costing_method' => 'fifo',
            ])->id,
            'production_order_id' => null,
            'warehouse_id' => null,
            'code' => mb_strtoupper(fake()->unique()->bothify('LOT-#####')),
            'quantity' => fake()->randomFloat(4, 1, 100),
            'produced_at' => now(),
        ];
    }
}
