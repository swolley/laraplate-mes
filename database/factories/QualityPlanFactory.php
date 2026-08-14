<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\MES\Models\QualityPlan;
use Override;

/**
 * @extends Factory<QualityPlan>
 */
final class QualityPlanFactory extends Factory
{
    /**
     * @var class-string<QualityPlan>
     */
    protected $model = QualityPlan::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $company_id = Company::query()->withoutGlobalScopes()->first()?->id
            ?? Company::query()->withoutGlobalScopes()->create([
                'slug' => fake()->unique()->slug(2),
                'name' => fake()->company(),
                'fiscal_country' => 'IT',
                'default_currency' => 'EUR',
            ])->id;

        return [
            'company_id' => $company_id,
            'item_id' => Item::query()->withoutGlobalScopes()->create([
                'company_id' => $company_id,
                'name' => fake()->words(3, true),
                'sku' => mb_strtoupper(fake()->unique()->bothify('QP-####')),
                'uom' => 'pcs',
                'costing_method' => 'fifo',
            ])->id,
            'routing_operation_id' => null,
            'name' => fake()->randomElement(['Final inspection', 'In-process control']),
            'version' => 'v' . fake()->numberBetween(1, 9),
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
