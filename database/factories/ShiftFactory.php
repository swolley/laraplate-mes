<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ERP\Models\Company;
use Modules\MES\Models\Shift;
use Override;

/**
 * @extends Factory<Shift>
 */
final class ShiftFactory extends Factory
{
    /**
     * @var class-string<Shift>
     */
    protected $model = Shift::class;

    /**
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
            'name' => mb_strtoupper(fake()->unique()->bothify('SHIFT-??')),
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'is_active' => true,
        ];
    }
}
