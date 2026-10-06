<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Models\MachineProfile;
use Override;

/**
 * @extends Factory<MachineProfile>
 */
final class MachineProfileFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineProfile>
     */
    protected $model = MachineProfile::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'vendor' => fake()->company(),
            'model' => mb_strtoupper(fake()->unique()->bothify('M-###')),
            'version' => '1.0',
            'definition' => ['signals' => []],
        ];
    }
}
