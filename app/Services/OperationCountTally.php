<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Events\OperationTargetReached;
use Modules\MES\Machine\Counts\CountTotals;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Keeps the machine quantities of an operation equal to the sum of its attributed count rows, so they can
 * be recomputed at any time. The first time the good pieces reach the order's planned quantity the
 * operation is stamped and {@see OperationTargetReached} is dispatched; a recount never clears the stamp.
 * Only an operation in progress is announced: late counts of a closed one still update its quantities.
 */
final class OperationCountTally
{
    public function refresh(int $operation_id): void
    {
        $operation = ProductionOrderOperation::query()->with('productionOrder')->find($operation_id);

        if (! $operation instanceof ProductionOrderOperation) {
            return;
        }

        $good = 0.0;
        $scrap = 0.0;

        foreach ($this->sumsByDevice($operation_id) as $device) {
            $effective = CountTotals::effective($device['good'], $device['scrap'], $device['total']);
            $good += $effective['good'];
            $scrap += $device['scrap'];
        }

        $operation->forceFill(['machine_good_quantity' => $good, 'machine_scrap_quantity' => $scrap])->save();

        $order = $operation->productionOrder;
        $planned = $order === null ? 0.0 : (float) $order->quantity_planned;

        if ($order === null || $planned <= 0.0 || $good < $planned || $operation->status !== ProductionOrderOperationStatus::InProgress) {
            return;
        }

        // The stamp and the announcement stand or fall together: a failed dispatch leaves no stamp, and a
        // concurrent refresh that lost the race stamps nothing and announces nothing.
        $operation->getConnection()->transaction(function () use ($operation, $order, $good, $planned): void {
            $stamped = ProductionOrderOperation::query()
                ->whereKey($operation->id)
                ->whereNull('target_reached_at')
                ->toBase()
                ->update(['target_reached_at' => now()->format('Y-m-d H:i:s')]);

            if ($stamped === 1) {
                OperationTargetReached::dispatch((int) $order->company_id, $operation->production_order_id, $operation->id, $operation->work_center_id, $good, $planned);
            }
        });
    }

    /**
     * @return list<array{good: float, scrap: float, total: float}>
     */
    private function sumsByDevice(int $operation_id): array
    {
        $rows = MachineCount::query()
            ->withoutGlobalScopes()
            ->toBase()
            ->where('production_order_operation_id', $operation_id)
            ->groupBy('device_id')
            ->selectRaw('SUM(good) as good, SUM(scrap) as scrap, SUM(total) as total')
            ->get();
        $sums = [];

        foreach ($rows as $row) {
            $sums[] = [
                'good' => is_numeric($row->good) ? (float) $row->good : 0.0,
                'scrap' => is_numeric($row->scrap) ? (float) $row->scrap : 0.0,
                'total' => is_numeric($row->total) ? (float) $row->total : 0.0,
            ];
        }

        return $sums;
    }
}
