<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Modules\MES\Data\WorkCenterKpis;
use Modules\MES\Models\WorkCenter;

/**
 * Computes the OEE and capacity of a work center for a calendar day and stores
 * them, so dashboards read a stored figure instead of recomputing it live.
 */
final class WorkCenterKpiMaterializer
{
    public function __construct(
        private OeeCalculatorService $oeeCalculator,
        private CapacityService $capacityService,
        private WorkCenterKpiStore $store,
    ) {}

    public function materialize(WorkCenter $work_center, DateTimeInterface $day): WorkCenterKpis
    {
        $from = Carbon::parse($day)->startOfDay();
        $to = $from->copy()->endOfDay();
        $id = $work_center->id;

        $planned = $this->capacityService->plannedMinutes($id, $from, $to);
        $availability = $this->oeeCalculator->availability($id, $from, $to, $planned);
        ['performance' => $performance, 'quality' => $quality] = $this->oeeCalculator->performanceAndQuality($id, $from, $to);

        $kpis = new WorkCenterKpis(
            work_center_id: $id,
            day: $from->toDateString(),
            availability: $availability,
            performance: $performance,
            quality: $quality,
            oee: $this->oeeCalculator->compose($availability, $performance, $quality),
            capacity_load: $this->capacityService->getCapacityLoad($id, $from, $to),
            available_minutes: $this->capacityService->availableMinutes($id, $from, $to),
            overloaded: $this->capacityService->checkOverload($id, $from, $to),
            computed_at: now()->toIso8601String(),
            incomplete_data: $this->oeeCalculator->hasIncompleteData($id, $from, $to),
        );

        $this->store->put($kpis);

        return $kpis;
    }
}
