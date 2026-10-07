<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\MES\Events\OperationTargetReached;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Keeps the machine quantities of an operation equal to the sum of its attributed count rows, so they can
 * be recomputed at any time. The first time the good pieces reach the order's planned quantity the
 * operation is stamped and {@see OperationTargetReached} is dispatched; a recount never clears the stamp.
 */
final class OperationCountTally
{
    public function refresh(int $operation_id): void
    {
        $operation = ProductionOrderOperation::query()->with('productionOrder')->find($operation_id);

        if (! $operation instanceof ProductionOrderOperation) {
            return;
        }

        $sums = MachineCount::query()
            ->withoutGlobalScopes()
            ->where('production_order_operation_id', $operation_id)
            ->selectRaw('COALESCE(SUM(good), 0) as good, COALESCE(SUM(scrap), 0) as scrap')
            ->first();

        $good = (float) ($sums->good ?? 0);
        $scrap = (float) ($sums->scrap ?? 0);
        $operation->update(['machine_good_quantity' => $good, 'machine_scrap_quantity' => $scrap]);

        $order = $operation->productionOrder;
        $planned = $order === null ? 0.0 : (float) $order->quantity_planned;

        if ($order === null || $operation->target_reached_at !== null || $planned <= 0.0 || $good < $planned) {
            return;
        }

        $operation->update(['target_reached_at' => now()]);

        OperationTargetReached::dispatch((int) $order->company_id, $operation->production_order_id, $operation->id, $operation->work_center_id, $good, $planned);
    }
}
