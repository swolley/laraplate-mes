<?php

declare(strict_types=1);

use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Services\ErpStockMovementRecorder;

/**
 * Validates: Requirements 12 — ERP dependency declared and clean,
 * contract binding registered in MES.
 *
 * Verifies that resolving StockMovementRecorder from the container
 * yields an ErpStockMovementRecorder instance, confirming the explicit
 * singleton binding registered in MESServiceProvider.
 */
test('container resolves StockMovementRecorder to ErpStockMovementRecorder', function (): void {
    $resolved = app(StockMovementRecorder::class);

    expect($resolved)->toBeInstanceOf(ErpStockMovementRecorder::class);
});

test('StockMovementRecorder binding is a singleton', function (): void {
    $first = app(StockMovementRecorder::class);
    $second = app(StockMovementRecorder::class);

    expect($first)->toBe($second);
});
