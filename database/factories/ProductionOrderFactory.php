<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\ERP\Models\Warehouse;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\ProductionOrder;
use Override;

/**
 * @extends Factory<ProductionOrder>
 */
final class ProductionOrderFactory extends Factory
{
    /**
     * @var class-string<ProductionOrder>
     */
    protected $model = ProductionOrder::class;

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
            'number' => mb_strtoupper(fake()->unique()->bothify('PO-#####')),
            'item_id' => Item::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'name' => fake()->words(3, true),
                'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####')),
                'uom' => 'pcs',
                'costing_method' => 'fifo',
            ])->id,
            'quantity_planned' => fake()->randomFloat(4, 1, 100),
            'quantity_produced' => null,
            'quantity_scrapped' => null,
            'uom' => 'pcs',
            'status' => ProductionOrderStatus::Draft->value,
            'planned_start_at' => now(),
            'planned_end_at' => now()->addDay(),
            'warehouse_id' => Warehouse::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'code' => mb_strtoupper(fake()->unique()->bothify('WH-##')),
                'name' => fake()->words(2, true),
            ])->id,
            'sales_order_id' => null,
            'sales_order_line_id' => null,
            'bom_snapshot' => ['id' => null, 'version' => null, 'lines' => []],
            'routing_snapshot' => ['id' => null, 'version' => null, 'operations' => []],
        ];
    }

    /**
     * Released order state.
     */
    public function released(): static
    {
        return $this->state(static fn () => ['status' => ProductionOrderStatus::Released->value]);
    }
}
