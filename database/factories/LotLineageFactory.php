<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\LotLineage;
use Modules\MES\Models\LotNumber;
use Override;

/**
 * @extends Factory<LotLineage>
 */
final class LotLineageFactory extends Factory
{
    /**
     * @var class-string<LotLineage>
     */
    protected $model = LotLineage::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'parent_lot_id' => LotNumber::factory(),
            'child_lot_id' => LotNumber::factory(),
            'production_order_id' => null,
            'quantity' => fake()->randomFloat(4, 1, 50),
        ];
    }
}
