<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\SparkplugAlias;
use Override;

/**
 * @extends Factory<SparkplugAlias>
 */
final class SparkplugAliasFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<SparkplugAlias>
     */
    protected $model = SparkplugAlias::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'source_id' => MachineSource::factory(),
            'device_external_id' => 'node1/dev2',
            'alias' => fake()->unique()->numberBetween(1, 100000),
            'name' => fake()->unique()->word(),
            'declared_at' => now(),
        ];
    }
}
