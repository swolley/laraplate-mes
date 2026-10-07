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

/**
 * A check of a plan with the given required samples per characteristic.
 *
 * @param  list<int>  $required_samples
 * @return array{check: QualityCheck, characteristics: list<Modules\MES\Models\QualityPlanCharacteristic>}
 */
function checkWithPlan(array $required_samples = [1]): array
{
    $plan = Modules\MES\Models\QualityPlan::factory()->create();
    $characteristics = [];

    foreach ($required_samples as $index => $required) {
        $characteristics[] = Modules\MES\Models\QualityPlanCharacteristic::factory()->create(['quality_plan_id' => $plan->id, 'characteristic' => "c{$index}", 'lower_limit' => 9, 'upper_limit' => 11, 'required_samples' => $required]);
    }

    return ['check' => QualityCheck::factory()->create(['quality_plan_id' => $plan->id]), 'characteristics' => $characteristics];
}

function measurementOf(Modules\MES\Models\QualityPlanCharacteristic $characteristic, float $value): array
{
    return ['characteristic' => $characteristic->characteristic, 'lower_limit' => 9, 'upper_limit' => 11, 'measured_value' => $value, 'quality_plan_characteristic_id' => $characteristic->id, 'source' => 'machine'];
}

it('records measurements without resolving the check', function (): void {
    ['check' => $check, 'characteristics' => [$length]] = checkWithPlan();

    $rows = resolve(QualityCheckService::class)->record($check, [measurementOf($length, 12.5)]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->is_within_limits)->toBeFalse()
        ->and($check->fresh()->status)->toBe(QualityCheckStatus::Pending)
        ->and(NonConformance::query()->count())->toBe(0);
});

it('resolves a check by its measurements and opens one non-conformance, not two', function (): void {
    ['check' => $check, 'characteristics' => [$length]] = checkWithPlan();
    $service = resolve(QualityCheckService::class);
    $service->record($check, [measurementOf($length, 12.5)]);

    $service->resolve($check);
    $service->resolve($check->fresh());

    expect($check->fresh()->status)->toBe(QualityCheckStatus::Failed)
        ->and($check->fresh()->checked_at)->not->toBeNull()
        ->and(NonConformance::query()->where('quality_check_id', $check->id)->count())->toBe(1);
});

it('passes a check whose every measurement is within limits, the limits being inclusive', function (): void {
    ['check' => $check, 'characteristics' => [$length]] = checkWithPlan();
    $service = resolve(QualityCheckService::class);

    $service->record($check, [measurementOf($length, 9.0), measurementOf($length, 11.0)]);

    expect($service->resolve($check)->status)->toBe(QualityCheckStatus::Passed);
});

it('opens a non-conformance for an out-of-limit measurement recorded after resolution, and keeps the status', function (): void {
    ['check' => $check, 'characteristics' => [$length]] = checkWithPlan();
    $service = resolve(QualityCheckService::class);
    $service->record($check, [measurementOf($length, 10)]);
    $service->resolve($check);

    $service->record($check->fresh(), [measurementOf($length, 11.01)]);

    expect($check->fresh()->status)->toBe(QualityCheckStatus::Passed)
        ->and(NonConformance::query()->where('quality_check_id', $check->id)->count())->toBe(1);
});

it('knows when every characteristic has its required samples', function (): void {
    ['check' => $check, 'characteristics' => [$length, $width]] = checkWithPlan([2, 1]);
    $service = resolve(QualityCheckService::class);

    $service->record($check, [measurementOf($length, 10), measurementOf($width, 10)]);
    expect($service->isComplete($check->fresh()))->toBeFalse();

    $service->record($check, [measurementOf($length, 10)]);
    expect($service->isComplete($check->fresh()))->toBeTrue();
});

it('is never complete for a plan without characteristics or a check without a plan', function (): void {
    $empty = QualityCheck::factory()->create(['quality_plan_id' => Modules\MES\Models\QualityPlan::factory()->create()->id]);

    expect(resolve(QualityCheckService::class)->isComplete($empty))->toBeFalse()
        ->and(resolve(QualityCheckService::class)->isComplete(QualityCheck::factory()->create()))->toBeFalse();
});
