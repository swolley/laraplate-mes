<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Override;

/**
 * @extends Factory<QualityCheckMeasurement>
 */
final class QualityCheckMeasurementFactory extends Factory
{
    /**
     * @var class-string<QualityCheckMeasurement>
     */
    protected $model = QualityCheckMeasurement::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'quality_check_id' => QualityCheck::factory(),
            'characteristic' => fake()->randomElement(['length', 'width', 'weight']),
            'nominal' => 10,
            'lower_limit' => 9,
            'upper_limit' => 11,
            'measured_value' => 10,
            'is_within_limits' => true,
        ];
    }
}
