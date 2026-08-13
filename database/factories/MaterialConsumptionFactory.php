<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\Warehouse;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\ProductionOrder;
use Override;

/**
 * @extends Factory<MaterialConsumption>
 */
final class MaterialConsumptionFactory extends Factory
{
    /**
     * @var class-string<MaterialConsumption>
     */
    protected $model = MaterialConsumption::class;

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

        $quantity = fake()->randomFloat(4, 1, 50);

        return [
            'production_order_id' => ProductionOrder::factory(),
            'production_order_operation_id' => null,
            'item_id' => Item::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'name' => fake()->words(3, true),
                'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####')),
                'uom' => 'pcs',
                'costing_method' => 'fifo',
            ])->id,
            'warehouse_id' => Warehouse::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'code' => mb_strtoupper(fake()->unique()->bothify('WH-##')),
                'name' => fake()->words(2, true),
            ])->id,
            'quantity_planned' => $quantity,
            'quantity_consumed' => $quantity,
            'variance' => 0,
            'uom' => 'pcs',
            'is_backflush' => true,
            'stock_shortage' => false,
            'recorded_at' => now(),
        ];
    }
}
