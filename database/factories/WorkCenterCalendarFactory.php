<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;
use Override;

/**
 * @extends Factory<WorkCenterCalendar>
 */
final class WorkCenterCalendarFactory extends Factory
{
    /**
     * @var class-string<WorkCenterCalendar>
     */
    protected $model = WorkCenterCalendar::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $startHour = fake()->numberBetween(6, 14);
        $endHour = $startHour + fake()->numberBetween(4, 10);
        $endHour = min($endHour, 23);

        return [
            'work_center_id' => WorkCenter::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => sprintf('%02d:00:00', $startHour),
            'end_time' => sprintf('%02d:00:00', $endHour),
        ];
    }

    /**
     * Set a specific day of week.
     */
    public function forDay(int $dayOfWeek): static
    {
        return $this->state(static fn () => ['day_of_week' => $dayOfWeek]);
    }
}
