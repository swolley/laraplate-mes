<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\MachineState;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineStateInterval;
use Override;

/**
 * @extends Factory<MachineStateInterval>
 */
final class MachineStateIntervalFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineStateInterval>
     */
    protected $model = MachineStateInterval::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $device = MachineDevice::factory()->create();

        return [
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'work_center_id' => $device->work_center_id,
            'state' => MachineState::Running->value,
            'started_at' => now(),
            'ended_at' => null,
        ];
    }
}
