<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\WorkCenter;
use Override;

/**
 * @extends Factory<MachineDevice>
 */
final class MachineDeviceFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineDevice>
     */
    protected $model = MachineDevice::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'source_id' => MachineSource::factory(),
            'external_id' => mb_strtolower(fake()->unique()->bothify('dev-####')),
            'work_center_id' => WorkCenter::factory(),
            'is_active' => true,
        ];
    }
}
