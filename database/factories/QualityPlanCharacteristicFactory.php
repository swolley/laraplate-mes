<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\QualityPlanCharacteristic;
use Override;

/**
 * @extends Factory<QualityPlanCharacteristic>
 */
final class QualityPlanCharacteristicFactory extends Factory
{
    /**
     * @var class-string<QualityPlanCharacteristic>
     */
    protected $model = QualityPlanCharacteristic::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'quality_plan_id' => QualityPlan::factory(),
            'characteristic' => fake()->randomElement(['Diameter', 'Length', 'Weight']),
            'nominal' => 10,
            'lower_limit' => 9,
            'upper_limit' => 11,
            'sort_order' => 0,
        ];
    }
}
