<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * @extends Factory<Downtime>
 */
final class DowntimeFactory extends Factory
{
    /**
     * @var class-string<Downtime>
     */
    protected $model = Downtime::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $work_center = WorkCenter::factory()->create();

        return [
            'company_id' => $work_center->company_id,
            'work_center_id' => $work_center->id,
            'production_order_operation_id' => null,
            'cause' => fake()->randomElement(DowntimeCause::cases())->value,
            'started_at' => now(),
            'ended_at' => null,
            'duration_minutes' => null,
            'notes' => null,
        ];
    }

    /**
     * A closed downtime of a given duration in minutes.
     */
    public function closed(float $duration_minutes = 30): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes((int) $duration_minutes),
            'ended_at' => now(),
            'duration_minutes' => $duration_minutes,
        ]);
    }
}
