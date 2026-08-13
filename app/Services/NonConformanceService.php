<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\NonConformanceDisposition;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Models\NonConformance;

/**
 * Drives the non-conformance lifecycle, creating a rework production order when
 * the chosen disposition is rework.
 */
final class NonConformanceService
{
    public function __construct(private ProductionOrderService $productionOrderService) {}

    /**
     * Resolve a non-conformance with a disposition. A rework disposition spawns
     * a new production order for the affected quantity.
     *
     * @throws DomainException when the non-conformance is no longer actionable,
     *                         or a rework is requested without a source order.
     */
    public function resolve(NonConformance $non_conformance, NonConformanceDisposition $disposition): NonConformance
    {
        throw_unless(
            $non_conformance->status->isActionable(),
            new DomainException("Non-conformance {$non_conformance->id} is not actionable in status {$non_conformance->status->value}."),
        );

        return DB::transaction(function () use ($non_conformance, $disposition): NonConformance {
            $rework_order_id = $disposition === NonConformanceDisposition::Rework
                ? $this->createReworkOrder($non_conformance)
                : null;

            $non_conformance->update([
                'disposition' => $disposition->value,
                'status' => NonConformanceStatus::Resolved->value,
                'resolved_at' => now(),
                'rework_production_order_id' => $rework_order_id,
            ]);

            return $non_conformance->refresh();
        });
    }

    /**
     * Close a resolved non-conformance.
     *
     * @throws DomainException when the non-conformance has not been resolved.
     */
    public function close(NonConformance $non_conformance): NonConformance
    {
        throw_unless(
            $non_conformance->status === NonConformanceStatus::Resolved,
            new DomainException("Non-conformance {$non_conformance->id} must be resolved before closing."),
        );

        $non_conformance->update([
            'status' => NonConformanceStatus::Closed->value,
        ]);

        return $non_conformance->refresh();
    }

    private function createReworkOrder(NonConformance $non_conformance): int
    {
        $source = $non_conformance->productionOrder;

        throw_if(
            $source === null,
            new DomainException("Non-conformance {$non_conformance->id} has no source production order to rework."),
        );

        $rework = $this->productionOrderService->create([
            'company_id' => $non_conformance->company_id,
            'item_id' => $non_conformance->item_id,
            'quantity_planned' => max(1.0, (float) $non_conformance->quantity),
            'uom' => $source->uom,
            'planned_start_at' => now(),
            'planned_end_at' => now()->addDay(),
            'warehouse_id' => $source->warehouse_id,
        ]);

        return $rework->id;
    }
}
