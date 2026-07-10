<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\MES\Enums\WorkCenterType;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * @extends Factory<WorkCenter>
 */
final class WorkCenterFactory extends Factory
{
    /**
     * @var class-string<WorkCenter>
     */
    protected $model = WorkCenter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => Company::query()->withoutGlobalScopes()->first()?->id
                ?? Company::query()->withoutGlobalScopes()->create([
                    'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
                    'name' => fake()->company(),
                    'fiscal_country' => 'IT',
                    'default_currency' => 'EUR',
                ])->id,
            'code' => mb_strtoupper(fake()->unique()->lexify('WC-????')),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(WorkCenterType::cases())->value,
            'capacity_per_hour' => fake()->randomFloat(4, 1, 100),
            'capacity_uom' => fake()->randomElement(['pcs', 'kg', 'lt', 'm']),
            'is_active' => true,
        ];
    }

    /**
     * Inactive work center state.
     */
    public function inactive(): static
    {
        return $this->state(static fn () => ['is_active' => false]);
    }

    /**
     * Work center of a specific type.
     */
    public function ofType(WorkCenterType $type): static
    {
        return $this->state(static fn () => ['type' => $type->value]);
    }
}
