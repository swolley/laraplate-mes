<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\QualityCheck;
use Override;

/**
 * @extends Factory<QualityCheck>
 */
final class QualityCheckFactory extends Factory
{
    /**
     * @var class-string<QualityCheck>
     */
    protected $model = QualityCheck::class;

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
            'production_order_operation_id' => null,
            'item_id' => $order->item_id,
            'name' => fake()->randomElement(['Dimensional check', 'Visual inspection', 'Weight control']),
            'status' => QualityCheckStatus::Pending->value,
            'notes' => null,
            'checked_at' => null,
        ];
    }
}
