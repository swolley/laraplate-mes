<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\NonConformanceDisposition;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Services\NonConformanceService;
use Modules\MES\Services\QualityCheckService;

uses(RefreshDatabase::class);

it('passes a check when every measurement is within limits', function (): void {
    $check = QualityCheck::factory()->create();

    $result = resolve(QualityCheckService::class)->execute($check, [
        ['characteristic' => 'length', 'lower_limit' => 9, 'upper_limit' => 11, 'measured_value' => 10],
    ]);

    expect($result->status)->toBe(QualityCheckStatus::Passed)
        ->and($result->measurements)->toHaveCount(1)
        ->and(NonConformance::query()->count())->toBe(0);
});

it('fails a check and opens a non-conformance when a measurement is out of limits', function (): void {
    $check = QualityCheck::factory()->create();

    $result = resolve(QualityCheckService::class)->execute($check, [
        ['characteristic' => 'length', 'lower_limit' => 9, 'upper_limit' => 11, 'measured_value' => 12.5],
    ]);

    $non_conformance = NonConformance::query()->where('quality_check_id', $check->id)->first();

    expect($result->status)->toBe(QualityCheckStatus::Failed)
        ->and($non_conformance)->not->toBeNull()
        ->and($non_conformance->status)->toBe(NonConformanceStatus::Open);
});

it('creates and links a rework production order on a rework disposition', function (): void {
    $non_conformance = NonConformance::factory()->create(['quantity' => 3]);
    $orders_before = ProductionOrder::query()->count();

    $resolved = resolve(NonConformanceService::class)
        ->resolve($non_conformance, NonConformanceDisposition::Rework);

    expect($resolved->status)->toBe(NonConformanceStatus::Resolved)
        ->and($resolved->disposition)->toBe(NonConformanceDisposition::Rework)
        ->and($resolved->rework_production_order_id)->not->toBeNull()
        ->and(ProductionOrder::query()->count())->toBe($orders_before + 1);
});

it('resolves a scrap disposition without a rework order', function (): void {
    $non_conformance = NonConformance::factory()->create();

    $resolved = resolve(NonConformanceService::class)
        ->resolve($non_conformance, NonConformanceDisposition::Scrap);

    expect($resolved->status)->toBe(NonConformanceStatus::Resolved)
        ->and($resolved->rework_production_order_id)->toBeNull();
});

it('closes a resolved non-conformance but refuses an open one', function (): void {
    $service = resolve(NonConformanceService::class);

    $resolved = $service->resolve(
        NonConformance::factory()->create(),
        NonConformanceDisposition::UseAsIs,
    );
    expect($service->close($resolved)->status)->toBe(NonConformanceStatus::Closed);

    $open = NonConformance::factory()->create();
    expect(fn () => $service->close($open))->toThrow(DomainException::class);
});
