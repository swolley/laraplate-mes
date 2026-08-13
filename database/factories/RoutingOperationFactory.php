<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * @extends Factory<RoutingOperation>
 */
final class RoutingOperationFactory extends Factory
{
    /**
     * @var class-string<RoutingOperation>
     */
    protected $model = RoutingOperation::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'routing_id' => Routing::factory(),
            'work_center_id' => WorkCenter::factory(),
            'sequence' => fake()->numberBetween(1, 20),
            'description' => fake()->sentence(3),
            'setup_time_minutes' => fake()->numberBetween(0, 60),
            'cycle_time_minutes' => fake()->randomFloat(4, 0.5, 30),
            'is_parallel' => false,
        ];
    }

    /**
     * Parallel operation state (may run concurrently with a same-sequence peer).
     */
    public function parallel(): static
    {
        return $this->state(static fn () => ['is_parallel' => true]);
    }
}
