<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Override;

/**
 * @extends Factory<BomLine>
 */
final class BomLineFactory extends Factory
{
    /**
     * @var class-string<BomLine>
     */
    protected $model = BomLine::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $company_id = Company::query()->withoutGlobalScopes()->first()?->id;

        return [
            'bom_id' => Bom::factory(),
            'item_id' => Item::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id ?? Company::query()->withoutGlobalScopes()->value('id'),
                'name' => fake()->words(3, true),
                'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####')),
                'uom' => 'pcs',
                'costing_method' => 'fifo',
            ])->id,
            'quantity' => fake()->randomFloat(4, 1, 10),
            'uom' => 'pcs',
            'consumption_method' => ConsumptionMethod::Backflush->value,
            'routing_operation_id' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Manually-consumed line state.
     */
    public function manual(): static
    {
        return $this->state(['consumption_method' => ConsumptionMethod::Manual->value]);
    }
}
