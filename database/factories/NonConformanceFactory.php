<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Override;

/**
 * @extends Factory<NonConformance>
 */
final class NonConformanceFactory extends Factory
{
    /**
     * @var class-string<NonConformance>
     */
    protected $model = NonConformance::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $order = ProductionOrder::factory()->create();

        return [
            'company_id' => $order->company_id,
            'production_order_id' => $order->id,
            'quality_check_id' => null,
            'item_id' => $order->item_id,
            'rework_production_order_id' => null,
            'status' => NonConformanceStatus::Open->value,
            'disposition' => null,
            'quantity' => fake()->randomFloat(4, 1, 20),
            'description' => fake()->sentence(),
            'resolved_at' => null,
        ];
    }
}
