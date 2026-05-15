<?php

declare(strict_types=1);

use Modules\ERP\Services\Inventory\StockMovementService;
use Modules\MES\Data\StockMovementData;
use Modules\MES\Services\ErpStockMovementRecorder;

describe('ErpStockMovementRecorder', function (): void {
    beforeEach(function (): void {
        $this->stockMovementService = Mockery::mock(StockMovementService::class);
        $this->recorder = new ErpStockMovementRecorder($this->stockMovementService);
    });

    it('calls recordInbound on the ERP service when direction is in', function (): void {
        $data = new StockMovementData(
            item_id: 10,
            warehouse_id: 20,
            company_id: 1,
            direction: 'in',
            quantity: 5,
            source_type: 'mes_production_orders',
            source_id: 99,
            occurred_at: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $this->stockMovementService
            ->shouldReceive('recordInbound')
            ->once()
            ->with(1, 10, 20, 5, 0, null);

        $this->recorder->record($data);
    });

    it('calls recordOutbound on the ERP service when direction is out', function (): void {
        $data = new StockMovementData(
            item_id: 10,
            warehouse_id: 20,
            company_id: 1,
            direction: 'out',
            quantity: 3,
            source_type: 'mes_production_orders',
            source_id: 99,
            occurred_at: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $this->stockMovementService
            ->shouldReceive('recordOutbound')
            ->once()
            ->with(1, 10, 20, 3, null);

        $this->recorder->record($data);
    });

    it('does not call recordOutbound when direction is in', function (): void {
        $data = new StockMovementData(
            item_id: 10,
            warehouse_id: 20,
            company_id: 1,
            direction: 'in',
            quantity: 5,
            source_type: 'mes_production_orders',
            source_id: 99,
            occurred_at: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $this->stockMovementService->shouldReceive('recordInbound')->once();
        $this->stockMovementService->shouldNotReceive('recordOutbound');

        $this->recorder->record($data);
    });

    it('does not call recordInbound when direction is out', function (): void {
        $data = new StockMovementData(
            item_id: 10,
            warehouse_id: 20,
            company_id: 1,
            direction: 'out',
            quantity: 3,
            source_type: 'mes_production_orders',
            source_id: 99,
            occurred_at: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $this->stockMovementService->shouldReceive('recordOutbound')->once();
        $this->stockMovementService->shouldNotReceive('recordInbound');

        $this->recorder->record($data);
    });
});
