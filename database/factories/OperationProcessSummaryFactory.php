<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\MES\Database\Factories\Concerns\UsesDefaultCompany;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\OperationProcessSummary;
use Modules\MES\Models\ProductionOrderOperation;
use Override;

/**
 * @extends Factory<OperationProcessSummary>
 */
final class OperationProcessSummaryFactory extends Factory
{
    use UsesDefaultCompany;

    /**
     * @var class-string<OperationProcessSummary>
     */
    protected $model = OperationProcessSummary::class;

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
            'production_order_operation_id' => ProductionOrderOperation::factory(),
            'signal_id' => $signal->id,
            'min' => 1,
            'max' => 3,
            'avg' => 2,
            'count' => 3,
            'out_of_range_count' => 0,
            'first_ts' => now()->subMinutes(5),
            'last_ts' => now(),
        ];
    }
}
