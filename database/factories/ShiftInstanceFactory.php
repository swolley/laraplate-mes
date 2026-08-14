<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Models\Shift;
use Modules\MES\Models\ShiftInstance;
use Override;

/**
 * @extends Factory<ShiftInstance>
 */
final class ShiftInstanceFactory extends Factory
{
    /**
     * @var class-string<ShiftInstance>
     */
    protected $model = ShiftInstance::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'work_center_id' => null,
            'date' => now()->toDateString(),
            'starts_at' => now()->startOfDay()->addHours(6),
            'ends_at' => now()->startOfDay()->addHours(14),
        ];
    }

    /**
     * A shift instance that currently covers the given work center.
     */
    public function coveringNow(int $work_center_id): static
    {
        return $this->state([
            'work_center_id' => $work_center_id,
            'date' => now()->toDateString(),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
