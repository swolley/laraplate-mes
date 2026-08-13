<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\OperatorLogAction;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Jobs\BackflushMaterialsJob;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Drives execution of a production order's operations: generation from the
 * frozen routing snapshot and the planned -> in_progress -> completed / skipped
 * transitions, computing operator efficiency on completion.
 */
final class ProductionOrderOperationService
{
    private const float MAX_EFFICIENCY = 999.99;

    public function __construct(private ShiftVerificationService $shiftVerificationService) {}

    /**
     * Materialise operations for a released order from its routing snapshot.
     *
     * @return Collection<int, ProductionOrderOperation>
     */
    public function generateForOrder(ProductionOrder $order): Collection
    {
        $operations = $order->routing_snapshot['operations'] ?? [];

        return DB::transaction(static function () use ($order, $operations): Collection {
            $created = collect();

            foreach ($operations as $operation) {
                $created->push(ProductionOrderOperation::query()->create([
                    'production_order_id' => $order->id,
                    'routing_operation_id' => $operation['routing_operation_id'] ?? null,
                    'work_center_id' => $operation['work_center_id'],
                    'sequence' => $operation['sequence'],
                    'description' => $operation['description'] ?? '',
                    'status' => ProductionOrderOperationStatus::Planned->value,
                    'setup_time_minutes' => $operation['setup_time_minutes'] ?? 0,
                    'cycle_time_minutes' => $operation['cycle_time_minutes'] ?? 0,
                    'is_parallel' => $operation['is_parallel'] ?? false,
                ]));
            }

            return $created;
        });
    }

    /**
     * Begin an operation.
     *
     * @throws DomainException when the operation is already started or finished.
     */
    public function start(ProductionOrderOperation $operation): ProductionOrderOperation
    {
        throw_unless(
            $operation->status->canStart(),
            new DomainException("Operation {$operation->id} cannot start from status {$operation->status->value}."),
        );

        $operation->update([
            'status' => ProductionOrderOperationStatus::InProgress->value,
            'actual_start_at' => now(),
        ]);

        $this->shiftVerificationService->logOperatorAction($operation, OperatorLogAction::Started);

        return $operation->refresh();
    }

    /**
     * Complete an operation, recording actual minutes and efficiency.
     *
     * Efficiency is standard minutes over actual minutes as a percentage,
     * clamped to [0, 999.99]. Backflush (Task 8) and quality checks (Task 10)
     * are dispatched by their own listeners on the completion event.
     *
     * @throws DomainException when the operation is not in progress.
     */
    public function complete(ProductionOrderOperation $operation, ?float $actual_minutes = null): ProductionOrderOperation
    {
        throw_unless(
            $operation->status === ProductionOrderOperationStatus::InProgress,
            new DomainException("Operation {$operation->id} cannot be completed from status {$operation->status->value}."),
        );

        $ended_at = now();
        $actual = $actual_minutes ?? ($operation->actual_start_at?->diffInMinutes($ended_at) ?? 0.0);

        $operation->update([
            'status' => ProductionOrderOperationStatus::Completed->value,
            'actual_end_at' => $ended_at,
            'actual_minutes' => $actual,
            'efficiency' => $this->efficiency($operation, (float) $actual),
        ]);

        $this->shiftVerificationService->logOperatorAction($operation, OperatorLogAction::Completed);
        BackflushMaterialsJob::dispatch($operation->id);

        return $operation->refresh();
    }

    /**
     * Skip an operation that is not required for this order.
     *
     * @throws DomainException when the operation has already completed.
     */
    public function skip(ProductionOrderOperation $operation): ProductionOrderOperation
    {
        throw_if(
            $operation->status === ProductionOrderOperationStatus::Completed,
            new DomainException("Operation {$operation->id} is already completed and cannot be skipped."),
        );

        $operation->update(['status' => ProductionOrderOperationStatus::Skipped->value]);

        return $operation->refresh();
    }

    /**
     * Standard-over-actual efficiency percentage, clamped to [0, 999.99].
     */
    private function efficiency(ProductionOrderOperation $operation, float $actual_minutes): float
    {
        if ($actual_minutes <= 0.0) {
            return 0.0;
        }

        $quantity = (float) ($operation->productionOrder->quantity_planned ?? 0.0);
        $standard = (float) $operation->setup_time_minutes + (float) $operation->cycle_time_minutes * $quantity;

        return min(self::MAX_EFFICIENCY, max(0.0, $standard / $actual_minutes * 100));
    }
}
