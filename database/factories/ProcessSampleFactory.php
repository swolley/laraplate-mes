<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\ProcessSample;
use Override;

/**
 * @extends Factory<ProcessSample>
 */
final class ProcessSampleFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<ProcessSample>
     */
    protected $model = ProcessSample::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $device = MachineDevice::factory()->create();
        $signal = MachineSignal::factory()->create(['device_id' => $device->id, 'role' => SignalRole::ProcessValue->value, 'config' => []]);

        return [
            'company_id' => $device->company_id,
            'signal_id' => $signal->id,
            'device_id' => $device->id,
            'work_center_id' => $device->work_center_id,
            'production_order_operation_id' => null,
            'ts' => now(),
            'value' => 50,
            'quality' => 'good',
        ];
    }
}
