<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * @extends Factory<ProductionOrderOperation>
 */
final class ProductionOrderOperationFactory extends Factory
{
    /**
     * @var class-string<ProductionOrderOperation>
     */
    protected $model = ProductionOrderOperation::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'production_order_id' => ProductionOrder::factory(),
            'routing_operation_id' => null,
            'work_center_id' => WorkCenter::factory(),
            'sequence' => fake()->numberBetween(1, 20),
            'description' => fake()->sentence(3),
            'status' => ProductionOrderOperationStatus::Planned->value,
            'setup_time_minutes' => fake()->numberBetween(0, 30),
            'cycle_time_minutes' => fake()->randomFloat(4, 0.5, 10),
            'is_parallel' => false,
        ];
    }

    /**
     * In-progress operation state.
     */
    public function inProgress(): static
    {
        return $this->state(static fn () => [
            'status' => ProductionOrderOperationStatus::InProgress->value,
            'actual_start_at' => now(),
        ]);
    }
}
