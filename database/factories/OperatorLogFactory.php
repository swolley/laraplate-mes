<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Enums\OperatorLogAction;
use Modules\MES\Models\OperatorLog;
use Modules\MES\Models\ProductionOrderOperation;
use Override;

/**
 * @extends Factory<OperatorLog>
 */
final class OperatorLogFactory extends Factory
{
    /**
     * @var class-string<OperatorLog>
     */
    protected $model = OperatorLog::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'user_id' => null,
            'production_order_operation_id' => ProductionOrderOperation::factory(),
            'shift_instance_id' => null,
            'action' => fake()->randomElement(OperatorLogAction::cases())->value,
            'logged_at' => now(),
        ];
    }
}
