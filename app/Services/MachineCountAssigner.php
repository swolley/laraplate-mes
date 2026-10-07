<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Gives an operation the counts its work center reported with nobody to attribute them to, by time range.
 */
final class MachineCountAssigner
{
    public function __construct(
        private readonly OperationCountTally $tally,
    ) {}

    /**
     * @return int how many count rows were attributed (`from <= ts < to`)
     */
    public function assign(int $operation_id, DateTimeInterface $from, DateTimeInterface $to): int
    {
        $operation = ProductionOrderOperation::query()->with('productionOrder')->findOrFail($operation_id);
        $company_id = $operation->productionOrder?->company_id;

        $assigned = MachineCount::query()
            ->where('work_center_id', $operation->work_center_id)
            ->when($company_id !== null, static fn ($query) => $query->where('company_id', $company_id))
            ->whereNull('production_order_operation_id')
            ->where('ts', '>=', MachineTime::db(Carbon::parse($from)))
            ->where('ts', '<', MachineTime::db(Carbon::parse($to)))
            ->update(['production_order_operation_id' => $operation->id]);

        $this->tally->refresh($operation->id);

        return $assigned;
    }
}
