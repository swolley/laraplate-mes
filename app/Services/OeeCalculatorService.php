<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Machine\MachineConnectivity;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;

/**
 * Computes Overall Equipment Effectiveness (OEE = Availability x Performance x
 * Quality) for a work center over a window. Every factor and the result are
 * clamped to [0, 1]. Availability counts only the part of each unplanned downtime
 * that falls inside the window.
 */
final class OeeCalculatorService
{
    public function __construct(
        private DowntimeService $downtimeService,
        private CapacityService $capacityService,
        private MachineConnectivity $connectivity,
    ) {}

    /**
     * OEE for a work center within a window, in [0, 1].
     */
    public function calculate(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, ?float $planned_minutes = null): float
    {
        $planned = $planned_minutes ?? $this->capacityService->plannedMinutes($work_center_id, $from, $to);

        return $this->compose(
            $this->availability($work_center_id, $from, $to, $planned),
            $this->performance($work_center_id, $from, $to),
            $this->quality($work_center_id, $from, $to),
        );
    }

    /**
     * Multiply the three OEE factors, each clamped to [0, 1].
     */
    public function compose(float $availability, float $performance, float $quality): float
    {
        return $this->clamp($availability) * $this->clamp($performance) * $this->clamp($quality);
    }

    /**
     * A work center fed by a machine follows ISO 22400: the busy time is the calendar time minus the planned
     * maintenance, and the availability is the share of it that was not lost to unplanned downtime (so
     * planned maintenance no longer counts against it). Any other work center keeps the planned-time formula.
     * For a connected work center `$planned_minutes` is the calendar time.
     */
    public function availability(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, float $planned_minutes): float
    {
        if ($this->connectivity->isConnected($work_center_id)) {
            $busy = $planned_minutes - $this->downtimeService->plannedMaintenanceMinutesWithin($work_center_id, $from, $to);

            if ($busy <= 0.0) {
                return 1.0;
            }

            $lost = min($busy, $this->downtimeService->unplannedMinutesWithin($work_center_id, $from, $to));

            return $this->clamp(($busy - $lost) / $busy);
        }

        if ($planned_minutes <= 0.0) {
            return 1.0;
        }

        $downtime = $this->downtimeService->unplannedMinutesWithin($work_center_id, $from, $to);

        return $this->clamp(($planned_minutes - $downtime) / $planned_minutes);
    }

    /**
     * Whether the machine stopped reporting during the window, so its figures may be understated.
     */
    public function hasIncompleteData(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): bool
    {
        return MachineStateInterval::query()
            ->where('work_center_id', $work_center_id)
            ->where('state', MachineState::Offline->value)
            ->where('started_at', '<', MachineTime::db(Carbon::parse($to)))
            ->where(static fn (Builder $query): Builder => $query->whereNull('ended_at')->orWhere('ended_at', '>', MachineTime::db(Carbon::parse($from))))
            ->exists();
    }

    public function performance(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $operations = $this->completedOperations($work_center_id, $from, $to)->get();

        $standard = $operations->sum(fn (ProductionOrderOperation $operation): float => (float) $operation->setup_time_minutes
            + (float) $operation->cycle_time_minutes * (float) ($operation->productionOrder->quantity_planned ?? 0.0));
        $actual = $operations->sum(static fn (ProductionOrderOperation $operation): float => (float) $operation->actual_minutes);

        if ($actual <= 0.0) {
            return 1.0;
        }

        return $this->clamp($standard / $actual);
    }

    public function quality(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $order_ids = ProductionOrderOperation::query()
            ->where('work_center_id', $work_center_id)
            ->pluck('production_order_id')
            ->unique();

        $checks = QualityCheck::query()
            ->whereIn('production_order_id', $order_ids)
            ->whereBetween('checked_at', [$from, $to]);

        $total = (clone $checks)->count();

        if ($total === 0) {
            return 1.0;
        }

        $passed = (clone $checks)->where('status', QualityCheckStatus::Passed->value)->count();

        return $this->clamp($passed / $total);
    }

    /**
     * @return Builder<ProductionOrderOperation>
     */
    private function completedOperations(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return ProductionOrderOperation::query()
            ->where('work_center_id', $work_center_id)
            ->where('status', ProductionOrderOperationStatus::Completed->value)
            ->whereBetween('actual_end_at', [$from, $to])
            ->with('productionOrder');
    }

    private function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
