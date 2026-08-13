<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;

/**
 * Computes Overall Equipment Effectiveness (OEE = Availability x Performance x
 * Quality) for a work center over a window. Every factor and the result are
 * clamped to [0, 1].
 */
final class OeeCalculatorService
{
    private const float DEFAULT_DAILY_MINUTES = 480.0;

    /**
     * OEE for a work center within a window, in [0, 1].
     */
    public function calculate(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, ?float $planned_minutes = null): float
    {
        $planned = $planned_minutes ?? $this->plannedMinutes($from, $to);

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

    public function availability(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, float $planned_minutes): float
    {
        if ($planned_minutes <= 0.0) {
            return 1.0;
        }

        $downtime = (float) Downtime::query()
            ->where('work_center_id', $work_center_id)
            ->where('cause', '!=', 'planned_maintenance')
            ->whereBetween('started_at', [$from, $to])
            ->sum('duration_minutes');

        return $this->clamp(($planned_minutes - $downtime) / $planned_minutes);
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

    private function plannedMinutes(DateTimeInterface $from, DateTimeInterface $to): float
    {
        $days = max(1, Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);

        return self::DEFAULT_DAILY_MINUTES * $days;
    }

    private function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
