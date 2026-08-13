<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Modules\MES\Enums\OperatorLogAction;
use Modules\MES\Models\OperatorLog;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\ShiftInstance;

/**
 * Records operator actions against operations and resolves the covering shift
 * instance. A missing shift is a non-blocking warning (decision D6): the
 * operator log is always written.
 */
final class ShiftVerificationService
{
    /**
     * Always log the operator action, attaching the covering shift instance
     * when one exists.
     */
    public function logOperatorAction(ProductionOrderOperation $operation, OperatorLogAction $action, ?int $user_id = null): OperatorLog
    {
        $shift_instance = $this->currentShiftInstance($operation->work_center_id);

        return OperatorLog::query()->create([
            'user_id' => $user_id ?? Auth::id(),
            'production_order_operation_id' => $operation->id,
            'shift_instance_id' => $shift_instance?->id,
            'action' => $action->value,
            'logged_at' => now(),
        ]);
    }

    /**
     * Whether a shift instance covers the work center at the given moment.
     */
    public function hasActiveShift(int $work_center_id, ?DateTimeInterface $at = null): bool
    {
        return $this->currentShiftInstance($work_center_id, $at) !== null;
    }

    /**
     * The shift instance covering a work center at the given moment, if any.
     */
    public function currentShiftInstance(int $work_center_id, ?DateTimeInterface $at = null): ?ShiftInstance
    {
        $moment = $at ?? now();

        return ShiftInstance::query()
            ->where('work_center_id', $work_center_id)
            ->where('starts_at', '<=', $moment)
            ->where('ends_at', '>=', $moment)
            ->first();
    }

    /**
     * Average efficiency of operations an operator completed within a window,
     * or null when the operator completed none.
     */
    public function averageEfficiencyForOperator(int $user_id, DateTimeInterface $from, DateTimeInterface $to): ?float
    {
        $operation_ids = OperatorLog::query()
            ->where('user_id', $user_id)
            ->where('action', OperatorLogAction::Completed->value)
            ->whereBetween('logged_at', [$from, $to])
            ->pluck('production_order_operation_id');

        $average = ProductionOrderOperation::query()
            ->whereIn('id', $operation_ids)
            ->whereNotNull('efficiency')
            ->avg('efficiency');

        return $average === null ? null : (float) $average;
    }
}
