<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Computes work-center load, schedule and completion estimates. Standard minutes
 * for an operation are its setup time plus its cycle time multiplied by the
 * order's planned quantity.
 *
 * An operation that carries planned dates counts toward a window in proportion
 * to its overlap with it; one without dates counts whole when its order overlaps
 * the window. Available minutes are the working minutes of the work center
 * calendar ({@see WorkCalendar}) less every downtime overlapping the window.
 *
 * Planning is forward and infinite-capacity: each operation starts when the
 * previous one ends (a parallel one with it), at the first working instant of
 * its own work center, without looking at what else is planned there.
 */
final class CapacityService
{
    public function __construct(
        private DowntimeService $downtimeService,
        private WorkCalendar $workCalendar,
    ) {}

    /**
     * Total standard minutes required on a work center within a window.
     * Always non-negative.
     */
    public function getCapacityLoad(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        return $this->operationsInWindow($from, $to)
            ->where('work_center_id', $work_center_id)
            ->get()
            ->sum(fn (ProductionOrderOperation $operation): float => $this->loadWithin($operation, $from, $to));
    }

    /**
     * Operations scheduled across a company's work centers within a window.
     *
     * @return Collection<int, ProductionOrderOperation>
     */
    public function getSchedule(int $company_id, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        return $this->operationsInWindow($from, $to)
            ->whereHas('productionOrder', static fn (Builder $query): Builder => $query->where('company_id', $company_id))
            ->with('productionOrder')
            ->orderBy('work_center_id')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Plan every operation still to do of an order from its planned start, and persist
     * the dates on the operations.
     *
     * @return Collection<int, ProductionOrderOperation>
     */
    public function scheduleOperations(ProductionOrder $order): Collection
    {
        $operations = $order->operations()
            ->where('status', ProductionOrderOperationStatus::Planned->value)
            ->orderBy('sequence')
            ->get();

        foreach ($this->plan($operations, CarbonImmutable::instance($order->planned_start_at), (float) $order->quantity_planned) as [$operation, $start, $end]) {
            $operation->update(['planned_start_at' => $start, 'planned_end_at' => $end]);
        }

        return $operations;
    }

    /**
     * When the order is expected to finish: the operations still to do, planned from now
     * (or from the planned start while that is still ahead). Without anything left to do, the
     * last actual end, or the planned end when the order has no operation.
     */
    public function estimateCompletionDate(ProductionOrder $order): Carbon
    {
        $operations = $order->operations()->orderBy('sequence')->get();
        $remaining = $operations->filter(static fn (ProductionOrderOperation $operation): bool => in_array($operation->status, [ProductionOrderOperationStatus::Planned, ProductionOrderOperationStatus::InProgress], true))->values();

        if ($remaining->isEmpty()) {
            $last_end = $operations->max('actual_end_at');

            return Carbon::parse($last_end ?? $order->planned_end_at);
        }

        $from = CarbonImmutable::instance($order->planned_start_at)->max(CarbonImmutable::now());
        $planned = $this->plan($remaining, $from, (float) $order->quantity_planned);

        $latest = $from;

        foreach ($planned as [, , $end]) {
            $latest = $latest->max($end);
        }

        return Carbon::instance($latest);
    }

    /**
     * Whether the load on a work center exceeds the available minutes in a window.
     * An explicit budget replaces the computed available minutes.
     */
    public function checkOverload(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, ?float $available_minutes = null): bool
    {
        $available = $available_minutes ?? $this->availableMinutes($work_center_id, $from, $to);

        return $available < $this->getCapacityLoad($work_center_id, $from, $to);
    }

    /**
     * Minutes a work center can work in a window: the working minutes of its calendar,
     * less every downtime overlapping the window, planned maintenance included (the work
     * center is out of service either way). Never negative.
     */
    public function availableMinutes(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $planned = $this->plannedMinutes($work_center_id, $from, $to);

        return max(0.0, $planned - $this->downtimeService->outOfServiceMinutesWithin($work_center_id, $from, $to));
    }

    /**
     * Calendar minutes of a window for a work center, before any downtime.
     */
    public function plannedMinutes(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        return $this->workCalendar->workingMinutesBetween($work_center_id, $from, $to);
    }

    /**
     * Reassign an operation to a different work center. With a date, the operation is
     * also planned again from that moment, at the first working instant of the new work center.
     */
    public function rescheduleOperation(ProductionOrderOperation $operation, int $work_center_id, ?DateTimeInterface $planned_start_at = null): ProductionOrderOperation
    {
        $attributes = ['work_center_id' => $work_center_id];

        if ($planned_start_at instanceof DateTimeInterface) {
            $start = $this->workCalendar->alignToWorking($work_center_id, $planned_start_at);
            $attributes['planned_start_at'] = $start;
            $attributes['planned_end_at'] = $this->workCalendar->addWorkingMinutes($work_center_id, $start, $this->standardMinutes($operation));
        }

        $operation->update($attributes);

        return $operation->refresh();
    }

    /**
     * @param  Collection<int, ProductionOrderOperation>  $operations  in sequence order
     * @return list<array{ProductionOrderOperation, CarbonImmutable, CarbonImmutable}>
     */
    private function plan(Collection $operations, CarbonImmutable $from, float $quantity): array
    {
        $planned = [];
        $previous_start = null;
        $previous_end = $from;

        foreach ($operations as $operation) {
            $earliest = $operation->is_parallel && $previous_start instanceof CarbonImmutable ? $previous_start : $previous_end;
            $start = $operation->actual_start_at instanceof DateTimeInterface
                ? CarbonImmutable::instance($operation->actual_start_at)
                : $this->workCalendar->alignToWorking($operation->work_center_id, $earliest);
            $end = $this->workCalendar->addWorkingMinutes($operation->work_center_id, $start, $this->standardMinutes($operation, $quantity));

            $planned[] = [$operation, $start, $end];
            $previous_start = $start;
            $previous_end = $end->max($previous_end);
        }

        return $planned;
    }

    /**
     * Operations that count toward a window: dated ones by their own dates, undated ones
     * by their order's planned dates.
     *
     * @return Builder<ProductionOrderOperation>
     */
    private function operationsInWindow(DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return ProductionOrderOperation::query()
            ->where(static function (Builder $query) use ($from, $to): void {
                $query->where(static fn (Builder $dated): Builder => $dated
                    ->whereNotNull('planned_start_at')
                    ->where('planned_start_at', '<=', $to)
                    ->where('planned_end_at', '>=', $from))
                    ->orWhere(static fn (Builder $undated): Builder => $undated
                        ->whereNull('planned_start_at')
                        ->whereHas('productionOrder', static fn (Builder $order): Builder => $order
                            ->where('planned_start_at', '<=', $to)
                            ->where('planned_end_at', '>=', $from)));
            });
    }

    private function loadWithin(ProductionOrderOperation $operation, DateTimeInterface $from, DateTimeInterface $to): float
    {
        $standard = $this->standardMinutes($operation);

        if (! $operation->planned_start_at instanceof DateTimeInterface || ! $operation->planned_end_at instanceof DateTimeInterface) {
            return $standard;
        }

        $duration = $operation->planned_start_at->diffInSeconds($operation->planned_end_at, true);

        if ($duration <= 0.0) {
            return $standard;
        }

        $overlap_start = $operation->planned_start_at->max(Carbon::parse($from));
        $overlap_end = $operation->planned_end_at->min(Carbon::parse($to));

        return $overlap_end > $overlap_start ? $standard * $overlap_start->diffInSeconds($overlap_end, true) / $duration : 0.0;
    }

    private function standardMinutes(ProductionOrderOperation $operation, ?float $quantity = null): float
    {
        $quantity ??= (float) ($operation->productionOrder->quantity_planned ?? 0.0);

        return (float) $operation->setup_time_minutes + (float) $operation->cycle_time_minutes * $quantity;
    }
}
