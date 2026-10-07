<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;

/**
 * Probes measure while an operation runs, but its quality check only exists once the operation completes.
 * When the check is created, the measurements that waited for it (same operation, a characteristic of its
 * plan) are put on it, in time order.
 */
final class UnattributedMeasurementAttacher
{
    public function __construct(
        private readonly UnattributedMeasurementAssigner $assigner,
    ) {}

    /**
     * @return int how many waiting measurements were attached
     */
    public function attachFor(QualityCheck $check): int
    {
        if ($check->production_order_operation_id === null || $check->quality_plan_id === null) {
            return 0;
        }

        $signals = MachineSignal::query()
            ->withoutGlobalScopes()
            ->whereIn('quality_plan_characteristic_id', QualityPlanCharacteristic::query()->where('quality_plan_id', $check->quality_plan_id)->select('id'))
            ->select('id');

        $attached = 0;

        UnattributedMeasurement::query()
            ->withoutGlobalScopes()
            ->where('production_order_operation_id', $check->production_order_operation_id)
            ->whereNull('assigned_at')
            ->whereIn('signal_id', $signals)
            ->orderBy('ts')
            ->get()
            ->each(function (UnattributedMeasurement $row) use ($check, &$attached): void {
                $this->assigner->assign($row, $check);
                $attached++;
            });

        return $attached;
    }
}
