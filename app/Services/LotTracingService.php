<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\MES\Models\LotLineage;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\OperationProcessSummary;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;

/**
 * Generates lot codes and walks the lot genealogy in both directions.
 *
 * Forward trace answers "where did this lot go" (descendants); backward trace
 * answers "what did this lot come from" (ancestors). The two are symmetric over
 * the {@see LotLineage} edges.
 */
final class LotTracingService
{
    /**
     * Build a lot code from the configured format
     * ({YEAR}{MONTH}{DAY}{SEQ}), with a per-company daily sequence.
     */
    public function generateLotCode(int $company_id, ?Carbon $on_date = null): string
    {
        $date = $on_date ?? now();
        $format = (string) config('mes.lots.number_format', '{YEAR}{MONTH}{DAY}-{SEQ}');

        $sequence = LotNumber::query()
            ->where('company_id', $company_id)
            ->whereDate('created_at', $date->toDateString())
            ->count() + 1;

        return str_replace(
            ['{YEAR}', '{MONTH}', '{DAY}', '{SEQ}'],
            [$date->format('Y'), $date->format('m'), $date->format('d'), mb_str_pad((string) $sequence, 4, '0', STR_PAD_LEFT)],
            $format,
        );
    }

    /**
     * Create the output lot for a completed production order.
     */
    public function createProductionLot(ProductionOrder $order, float $quantity, ?string $code = null): LotNumber
    {
        return LotNumber::query()->create([
            'company_id' => $order->company_id,
            'item_id' => $order->item_id,
            'production_order_id' => $order->id,
            'warehouse_id' => $order->warehouse_id,
            'code' => $code ?? $this->generateLotCode($order->company_id),
            'quantity' => $quantity,
            'produced_at' => now(),
        ]);
    }

    /**
     * Link a consumed (parent) lot to a produced (child) lot.
     */
    public function recordLineage(LotNumber $parent, LotNumber $child, ?int $production_order_id = null, ?float $quantity = null): LotLineage
    {
        return LotLineage::query()->firstOrCreate(
            ['parent_lot_id' => $parent->id, 'child_lot_id' => $child->id],
            ['production_order_id' => $production_order_id, 'quantity' => $quantity],
        );
    }

    /**
     * What the process signals did while each operation of the lot's production order ran: the link from a lot
     * to the process parameters it was made with.
     *
     * @return Collection<int, OperationProcessSummary>
     */
    public function processSummaries(int $lot_id): Collection
    {
        $order_id = LotNumber::query()->find($lot_id)?->production_order_id;

        if ($order_id === null) {
            return new Collection();
        }

        return OperationProcessSummary::query()
            ->whereIn('production_order_operation_id', ProductionOrderOperation::query()->where('production_order_id', $order_id)->select('id'))
            ->orderBy('production_order_operation_id')
            ->orderBy('signal_id')
            ->get();
    }

    /**
     * Descendant lot ids reachable from the given lot (where it went).
     *
     * @return list<int>
     */
    public function forwardTrace(int $lot_id): array
    {
        return $this->walk($lot_id, 'parent_lot_id', 'child_lot_id');
    }

    /**
     * Ancestor lot ids the given lot descends from (where it came from).
     *
     * @return list<int>
     */
    public function backwardTrace(int $lot_id): array
    {
        return $this->walk($lot_id, 'child_lot_id', 'parent_lot_id');
    }

    /**
     * Breadth-first walk of the lineage graph from a starting lot.
     *
     * @return list<int>
     */
    private function walk(int $start_lot_id, string $from_column, string $to_column): array
    {
        $visited = [];
        $queue = [$start_lot_id];

        while ($queue !== []) {
            $current = array_shift($queue);

            $next = LotLineage::query()
                ->where($from_column, $current)
                ->pluck($to_column)
                ->all();

            foreach ($next as $lot_id) {
                $lot_id = (int) $lot_id;

                if ($lot_id === $start_lot_id || in_array($lot_id, $visited, true)) {
                    continue;
                }

                $visited[] = $lot_id;
                $queue[] = $lot_id;
            }
        }

        return $visited;
    }
}
