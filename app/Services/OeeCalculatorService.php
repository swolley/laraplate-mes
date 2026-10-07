<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Machine\Counts\CountTotals;
use Modules\MES\Machine\MachineConnectivity;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\WorkCenter;

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
        private WorkCalendar $workCalendar,
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
     * For a connected work center `$planned_minutes` is the working calendar time of the window, and downtimes are measured by their working time too.
     */
    public function availability(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, float $planned_minutes): float
    {
        if ($this->connectivity->isConnected($work_center_id)) {
            // Only the working time of a stop counts: a machine stopped overnight is not a loss.
            $measure = fn (CarbonInterface $start, CarbonInterface $end): float => $this->workCalendar->workingMinutesBetween($work_center_id, $start, $end);
            $busy = $planned_minutes - $this->downtimeService->plannedMaintenanceMinutesWithin($work_center_id, $from, $to, $measure);

            if ($busy <= 0.0) {
                return 1.0;
            }

            $lost = min($busy, $this->downtimeService->unplannedMinutesWithin($work_center_id, $from, $to, $measure));

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

    /**
     * With machine counts in the window (ISO 22400): the ideal time of the counted pieces over the run time.
     * The ideal cycle is the operation's `cycle_time_minutes`; counts nobody attributed use the work center's
     * `60 / capacity_per_hour`. Without counts, the order-based formula.
     */
    public function performance(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $counts = $this->countsByOperation($work_center_id, $from, $to);

        if ($counts !== []) {
            return $this->countedPerformance($work_center_id, $from, $to, $counts);
        }

        $operations = $this->completedOperations($work_center_id, $from, $to)->get();

        $standard = $operations->sum(fn (ProductionOrderOperation $operation): float => (float) $operation->setup_time_minutes
            + (float) $operation->cycle_time_minutes * (float) ($operation->productionOrder->quantity_planned ?? 0.0));
        $actual = $operations->sum(static fn (ProductionOrderOperation $operation): float => (float) $operation->actual_minutes);

        if ($actual <= 0.0) {
            return 1.0;
        }

        return $this->clamp($standard / $actual);
    }

    /**
     * With machine counts in the window (ISO 22400): good pieces over total pieces. Without counts, the share of
     * passed quality checks.
     */
    public function quality(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $counts = $this->countsByOperation($work_center_id, $from, $to);

        if ($counts !== []) {
            $good = array_sum(array_column($counts, 'good'));
            $total = array_sum(array_column($counts, 'total'));

            return $total <= 0.0 ? 1.0 : $this->clamp($good / $total);
        }

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
     * @param  list<array{operation_id: ?int, good: float, total: float}>  $counts
     */
    private function countedPerformance(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, array $counts): float
    {
        $run = $this->runMinutes($work_center_id, $from, $to);

        if ($run <= 0.0) {
            return 1.0;
        }

        $work_center = WorkCenter::query()->withoutGlobalScopes()->find($work_center_id);
        $capacity = $work_center instanceof WorkCenter ? $this->number($work_center->capacity_per_hour) : 0.0;
        $cycles = ProductionOrderOperation::query()
            ->whereIn('id', array_filter(array_column($counts, 'operation_id')))
            ->pluck('cycle_time_minutes', 'id');
        $ideal = 0.0;

        foreach ($counts as $count) {
            $cycle = $count['operation_id'] === null
                ? ($capacity > 0.0 ? 60.0 / $capacity : 0.0)
                : $this->number($cycles[$count['operation_id']] ?? 0.0);
            $ideal += $cycle * $count['total'];
        }

        return $this->clamp($ideal / $run);
    }

    /**
     * Run time: the busy time minus the unplanned downtime, both in working time for a connected work center.
     */
    private function runMinutes(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $planned = $this->capacityService->plannedMinutes($work_center_id, $from, $to);

        if (! $this->connectivity->isConnected($work_center_id)) {
            return max(0.0, $planned - $this->downtimeService->unplannedMinutesWithin($work_center_id, $from, $to));
        }

        $measure = fn (CarbonInterface $start, CarbonInterface $end): float => $this->workCalendar->workingMinutesBetween($work_center_id, $start, $end);
        $busy = $planned - $this->downtimeService->plannedMaintenanceMinutesWithin($work_center_id, $from, $to, $measure);

        return max(0.0, $busy - $this->downtimeService->unplannedMinutesWithin($work_center_id, $from, $to, $measure));
    }

    /**
     * The counted pieces of the window by operation (null: nobody attributed them); the total is the sent
     * total, or good plus scrap when the device sends none.
     *
     * @return list<array{operation_id: ?int, good: float, total: float}>
     */
    private function countsByOperation(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $rows = MachineCount::query()
            ->withoutGlobalScopes()
            ->toBase()
            ->where('work_center_id', $work_center_id)
            ->where('ts', '>=', MachineTime::db(Carbon::parse($from)))
            ->where('ts', '<', MachineTime::db(Carbon::parse($to)))
            ->groupBy('production_order_operation_id', 'device_id')
            ->selectRaw('production_order_operation_id as operation_id, device_id, SUM(good) as good, SUM(scrap) as scrap, SUM(total) as total')
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $effective = CountTotals::effective($this->number($row->good), $this->number($row->scrap), $this->number($row->total));
            $counts[] = [
                'operation_id' => is_numeric($row->operation_id) ? (int) $row->operation_id : null,
                'good' => $effective['good'],
                'total' => $effective['total'],
            ];
        }

        return $counts;
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
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
