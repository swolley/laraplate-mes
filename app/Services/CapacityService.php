<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Computes work-center load and schedule from the standard minutes of the
 * operations planned within a window. Standard minutes for an operation are its
 * setup time plus its cycle time multiplied by the order's planned quantity.
 */
final class CapacityService
{
    private const float DEFAULT_DAILY_MINUTES = 480.0;

    /**
     * Total standard minutes required on a work center within a window.
     * Always non-negative.
     */
    public function getCapacityLoad(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to): float
    {
        return $this->operationsInWindow($from, $to)
            ->where('work_center_id', $work_center_id)
            ->get()
            ->sum(fn (ProductionOrderOperation $operation): float => $this->standardMinutes($operation));
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
     * Best available completion estimate for an order (its planned end).
     */
    public function estimateCompletionDate(ProductionOrder $order): Carbon
    {
        return Carbon::parse($order->planned_end_at);
    }

    /**
     * Whether the load on a work center exceeds the available minutes in a window.
     */
    public function checkOverload(int $work_center_id, DateTimeInterface $from, DateTimeInterface $to, ?float $available_minutes = null): bool
    {
        $available = $available_minutes ?? $this->availableMinutes($from, $to);

        return $available < $this->getCapacityLoad($work_center_id, $from, $to);
    }

    /**
     * Reassign an operation to a different work center.
     */
    public function rescheduleOperation(ProductionOrderOperation $operation, int $work_center_id): ProductionOrderOperation
    {
        $operation->update(['work_center_id' => $work_center_id]);

        return $operation->refresh();
    }

    /**
     * @return Builder<ProductionOrderOperation>
     */
    private function operationsInWindow(DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return ProductionOrderOperation::query()
            ->whereHas('productionOrder', static fn (Builder $query): Builder => $query
                ->where('planned_start_at', '<=', $to)
                ->where('planned_end_at', '>=', $from));
    }

    private function standardMinutes(ProductionOrderOperation $operation): float
    {
        $quantity = (float) ($operation->productionOrder->quantity_planned ?? 0.0);

        return (float) $operation->setup_time_minutes + (float) $operation->cycle_time_minutes * $quantity;
    }

    private function availableMinutes(DateTimeInterface $from, DateTimeInterface $to): float
    {
        $days = max(1, Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);

        return self::DEFAULT_DAILY_MINUTES * $days;
    }
}
