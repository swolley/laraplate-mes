<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Models\MachineSource;
use Override;

/**
 * @extends Factory<MachineSource>
 */
final class MachineSourceFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineSource>
     */
    protected $model = MachineSource::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'code' => mb_strtolower(fake()->unique()->lexify('gw-????')),
            'name' => fake()->words(2, true),
            'normalizer' => 'canonical',
            'transport' => MachineTransport::Http->value,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function mqtt(): static
    {
        return $this->state(['transport' => MachineTransport::Mqtt->value, 'mqtt_topic' => 'plant/laraplate-machine/1/gw']);
    }
}
