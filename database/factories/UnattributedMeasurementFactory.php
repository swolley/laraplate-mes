<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;
use Override;

/**
 * @extends Factory<UnattributedMeasurement>
 */
final class UnattributedMeasurementFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<UnattributedMeasurement>
     */
    protected $model = UnattributedMeasurement::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $device = MachineDevice::factory()->create();
        $signal = MachineSignal::factory()->create([
            'device_id' => $device->id,
            'role' => SignalRole::Measurement->value,
            'config' => [],
            'quality_plan_characteristic_id' => QualityPlanCharacteristic::factory()->create()->id,
        ]);

        return [
            'company_id' => $device->company_id,
            'signal_id' => $signal->id,
            'device_id' => $device->id,
            'work_center_id' => $device->work_center_id,
            'production_order_operation_id' => null,
            'ts' => now(),
            'value' => 10,
            'serial' => null,
            'context' => null,
            'assigned_at' => null,
        ];
    }
}
