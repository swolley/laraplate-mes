<?php

declare(strict_types=1);

namespace Modules\MES\Services;

use Modules\ERP\Services\Inventory\StockMovementService;
use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Enums\MESTables;
use Modules\MES\Models\ProductionOrder;

/**
 * Adapter that bridges the MES StockMovementRecorder contract to the ERP
 * StockMovementService. The MES depends on ERP (declared dependency), so
 * importing ERP classes here is intentional and correct.
 *
 * The ERP has no knowledge of the MES — dependency flows one way only.
 */
final readonly class ErpStockMovementRecorder implements StockMovementRecorder
{
    public function __construct(
        private StockMovementService $stockMovementService,
    ) {}

    public function record(StockMovementData $data): void
    {
        if ($data->direction === 'in') {
            $this->stockMovementService->recordInbound(
                company_id: $data->company_id,
                item_id: $data->item_id,
                warehouse_id: $data->warehouse_id,
                quantity: $data->quantity,
                unit_cost: $data->unit_cost ?? 0,
                source: $this->source($data),
            );

            return;
        }

        $this->stockMovementService->recordOutbound(
            company_id: $data->company_id,
            item_id: $data->item_id,
            warehouse_id: $data->warehouse_id,
            quantity: $data->quantity,
            source: $this->source($data),
        );
    }

    /**
     * The MES record the movement is posted for, so the ERP movement can be traced back to it.
     */
    private function source(StockMovementData $data): ?ProductionOrder
    {
        if ($data->source_type !== MESTables::ProductionOrders->value) {
            return null;
        }

        return ProductionOrder::query()->withoutGlobalScopes()->find($data->source_id);
    }
}
