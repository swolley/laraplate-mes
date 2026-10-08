<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\MESTables;
use Modules\MES\Machine\Process\ProcessStatistics;
use Modules\MES\Machine\Process\ProcessValueStore;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Writes, per process signal, what it did while an operation ran: the permanent link from a lot to the process
 * parameters it was made with. The numbers come from the process value store, so any driver supplies them. A
 * signal's row is only replaced when the store still has samples for it: pruned raw data never empties a summary.
 */
final class ProcessSummarizer
{
    public function __construct(
        private readonly ProcessValueStore $store,
    ) {}

    /**
     * @return int how many summary rows were written
     */
    public function summarize(int $operation_id): int
    {
        $operation = ProductionOrderOperation::query()->with('productionOrder')->find($operation_id);

        if (! $operation instanceof ProductionOrderOperation || $operation->productionOrder === null) {
            return 0;
        }

        $statistics = $this->store->operationStatistics($operation_id, []);

        if ($statistics === []) {
            return 0;
        }

        $ranges = $this->ranges(array_map(static fn (ProcessStatistics $row): int => $row->signal_id, $statistics));

        if ($ranges !== []) {
            $statistics = $this->store->operationStatistics($operation_id, $ranges);
        }

        $company_id = (int) $operation->productionOrder->company_id;
        $now = now()->format('Y-m-d H:i:s');

        foreach ($statistics as $row) {
            DB::table(MESTables::OperationProcessSummaries->value)->upsert(
                [[
                    'company_id' => $company_id,
                    'production_order_operation_id' => $operation_id,
                    'signal_id' => $row->signal_id,
                    'min' => $row->min,
                    'max' => $row->max,
                    'avg' => $row->avg,
                    'count' => $row->count,
                    'out_of_range_count' => $row->out_of_range_count,
                    'first_ts' => MachineTime::db($row->first_ts),
                    'last_ts' => MachineTime::db($row->last_ts),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['production_order_operation_id', 'signal_id'],
                ['min', 'max', 'avg', 'count', 'out_of_range_count', 'first_ts', 'last_ts', 'updated_at'],
            );
        }

        return count($statistics);
    }

    /**
     * The acceptable range of each signal that has one.
     *
     * @param  list<int>  $signal_ids
     * @return array<int, array{min: ?float, max: ?float}>
     */
    private function ranges(array $signal_ids): array
    {
        $ranges = [];

        foreach (MachineSignal::query()->withoutGlobalScopes()->whereIn('id', $signal_ids)->get() as $signal) {
            $config = is_array($signal->config) ? $signal->config : [];
            $min = isset($config['min']) && is_numeric($config['min']) ? (float) $config['min'] : null;
            $max = isset($config['max']) && is_numeric($config['max']) ? (float) $config['max'] : null;

            if ($min !== null || $max !== null) {
                $ranges[$signal->id] = ['min' => $min, 'max' => $max];
            }
        }

        return $ranges;
    }
}
