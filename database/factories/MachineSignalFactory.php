<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Override;

/**
 * @extends Factory<MachineSignal>
 */
final class MachineSignalFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<MachineSignal>
     */
    protected $model = MachineSignal::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'company_id' => $this->defaultCompanyId(),
            'device_id' => MachineDevice::factory(),
            'key' => mb_strtolower(fake()->unique()->bothify('sig_####')),
            'role' => SignalRole::GoodCount->value,
            'data_type' => 'number',
            'config' => ['mode' => 'delta'],
        ];
    }
}
