<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Illuminate\Support\Carbon;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlan;

/**
 * Creates pending {@see QualityCheck} records from the active {@see QualityPlan}
 * when an operation or an order completes. Non-blocking: absence of a plan is a
 * no-op, and creation is idempotent per (order, plan, operation) so replays and
 * re-completions never duplicate checks.
 */
final class QualityCheckPlanner
{
    public function __construct(private QualityPlanResolver $resolver) {}

    /**
     * Create the in-process check for a completed operation, if a plan targets
     * that routing operation.
     */
    public function forOperation(ProductionOrderOperation $operation): ?QualityCheck
    {
        $order = $operation->productionOrder;

        if (! $order instanceof ProductionOrder || $operation->routing_operation_id === null) {
            return null;
        }

        $plan = $this->resolver->resolve((int) $order->item_id, (int) $operation->routing_operation_id, Carbon::now());

        if (! $plan instanceof QualityPlan) {
            return null;
        }

        return $this->createCheck($plan, $order, (int) $operation->id);
    }

    /**
     * Create the final-inspection check for a completed order, if a plan targets
     * the finished item (no routing operation).
     */
    public function forOrderCompletion(ProductionOrder $order): ?QualityCheck
    {
        $plan = $this->resolver->resolve((int) $order->item_id, null, Carbon::now());

        if (! $plan instanceof QualityPlan) {
            return null;
        }

        return $this->createCheck($plan, $order, null);
    }

    private function createCheck(QualityPlan $plan, ProductionOrder $order, ?int $operation_id): ?QualityCheck
    {
        $already = QualityCheck::query()
            ->where('production_order_id', $order->id)
            ->where('quality_plan_id', $plan->id)
            ->when(
                $operation_id === null,
                static fn ($query) => $query->whereNull('production_order_operation_id'),
                static fn ($query) => $query->where('production_order_operation_id', $operation_id),
            )
            ->exists();

        if ($already) {
            return null;
        }

        return QualityCheck::query()->create([
            'company_id' => $order->company_id,
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation_id,
            'quality_plan_id' => $plan->id,
            'item_id' => $order->item_id,
            'name' => $plan->name,
            'status' => QualityCheckStatus::Pending->value,
        ]);
    }
}
