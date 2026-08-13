<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Services\OeeCalculatorService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('composes the three factors into a bounded oee', function (): void {
    // Availability 0.9 * Performance 0.8 * Quality 0.95 = 0.684.
    $oee = resolve(OeeCalculatorService::class)->compose(0.9, 0.8, 0.95);

    expect(round($oee, 3))->toBe(0.684)
        ->and($oee)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('clamps out-of-range factors before multiplying', function (): void {
    $service = resolve(OeeCalculatorService::class);

    expect($service->compose(1.5, 0.5, 2.0))->toBe(0.5) // 1 * 0.5 * 1
        ->and($service->compose(-0.2, 0.5, 0.5))->toBe(0.0);
});

it('returns an oee within [0, 1] for a work center', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    Downtime::factory()->closed(60)->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->subHour(),
        'ended_at' => now(),
    ]);

    $oee = resolve(OeeCalculatorService::class)->calculate(
        $work_center->id,
        now()->subDay(),
        now()->addDay(),
    );

    expect($oee)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('reduces availability as unplanned downtime grows', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $service = resolve(OeeCalculatorService::class);
    $full = $service->availability($work_center->id, now()->subDay(), now()->addDay(), 480.0);

    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now(),
        'ended_at' => now(),
        'duration_minutes' => 120,
    ]);

    $reduced = $service->availability($work_center->id, now()->subDay(), now()->addDay(), 480.0);

    expect($full)->toBe(1.0)
        ->and($reduced)->toBeLessThan($full)
        ->and($reduced)->toBe(0.75); // (480 - 120) / 480
});

it('computes and stores duration when a downtime is closed', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $service = resolve(DowntimeService::class);

    $downtime = $service->open($work_center, DowntimeCause::Setup);
    expect($service->isWorkCenterDown($work_center->id))->toBeTrue();

    $closed = $service->close($downtime);

    expect($closed->ended_at)->not->toBeNull()
        ->and((float) $closed->duration_minutes)->toBeGreaterThanOrEqual(0.0)
        ->and($service->isWorkCenterDown($work_center->id))->toBeFalse();
});
