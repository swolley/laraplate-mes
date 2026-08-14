<?php

declare(strict_types=1);

use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Services\DomainActions\MesDomainActionRegistrar;

it('registers every MES domain action without collision', function (): void {
    $registry = new DomainActionRegistry;

    // Registration itself asserts none of these verbs collide with Core's
    // reserved generic verbs (the D3 boot guard).
    new MesDomainActionRegistrar()->register($registry);

    $expected = [
        [ProductionOrder::class, 'release'],
        [ProductionOrder::class, 'complete'],
        [ProductionOrder::class, 'cancel'],
        [ProductionOrder::class, 'record_consumption'],
        [ProductionOrderOperation::class, 'start'],
        [ProductionOrderOperation::class, 'complete'],
        [ProductionOrderOperation::class, 'skip'],
        [QualityCheck::class, 'execute'],
        [NonConformance::class, 'resolve'],
        [NonConformance::class, 'close'],
        [Downtime::class, 'close'],
        [Bom::class, 'explode'],
        [LotNumber::class, 'forward_trace'],
        [LotNumber::class, 'backward_trace'],
    ];

    foreach ($expected as [$model, $action]) {
        expect($registry->has($model, $action))->toBeTrue();
    }
});

it('does not register unknown actions', function (): void {
    $registry = new DomainActionRegistry;
    new MesDomainActionRegistrar()->register($registry);

    expect($registry->has(ProductionOrder::class, 'teleport'))->toBeFalse()
        ->and($registry->resolve(ProductionOrder::class, 'release'))->toBeCallable();
});

it('is wired into the container registry at boot', function (): void {
    $registry = resolve(DomainActionRegistry::class);

    expect($registry->has(ProductionOrder::class, 'release'))->toBeTrue()
        ->and($registry->has(LotNumber::class, 'forward_trace'))->toBeTrue();
});
