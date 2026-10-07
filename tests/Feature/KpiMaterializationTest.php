<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\MES\Console\MaterializeKpisCommand;
use Modules\MES\Data\WorkCenterKpis;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Jobs\MaterializeWorkCenterKpisJob;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\WorkCenterKpiMaterializer;
use Modules\MES\Services\WorkCenterKpiStore;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('has nothing to show before the KPIs are materialised, and never recomputes on read', function (): void {
    $work_center = WorkCenter::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now()))->toBeNull();
});

it('materialises OEE and capacity for a work center day and serves them from the store', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->startOfDay()->addHour(),
        'ended_at' => now()->startOfDay()->addHours(2),
    ]);

    $kpis = resolve(WorkCenterKpiMaterializer::class)->materialize($work_center, now());
    $stored = resolve(WorkCenterKpiStore::class)->get($work_center->id, now());

    expect($stored)->toBeInstanceOf(WorkCenterKpis::class)
        ->and($stored->toArray())->toBe($kpis->toArray())
        ->and($stored->availability)->toBe(420.0 / 480.0)
        ->and($stored->oee)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($stored->available_minutes)->toBe(420.0)
        ->and($stored->capacity_load)->toBe(0.0)
        ->and($stored->overloaded)->toBeFalse();
});

it('does not move when the live data changes until the next materialisation', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $materializer = resolve(WorkCenterKpiMaterializer::class);
    $materializer->materialize($work_center, now());

    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->startOfDay(),
        'ended_at' => now()->startOfDay()->addHours(4),
    ]);

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now())->availability)->toBe(1.0);

    $materializer->materialize($work_center, now());

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now())->availability)->toBe(0.5);
});

it('materialises through the queued job', function (): void {
    $work_center = WorkCenter::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);

    new MaterializeWorkCenterKpisJob($work_center->id, now()->toDateString())->handle(resolve(WorkCenterKpiMaterializer::class));

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now()))->not->toBeNull();
});

it('dispatches one job per active work center from the command', function (): void {
    Queue::fake();
    $company = MesTestHelpers::makeCompany();
    $active = WorkCenter::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    WorkCenter::factory()->create(['company_id' => $company->id, 'is_active' => false]);

    $this->artisan(MaterializeKpisCommand::class)->assertSuccessful();

    Queue::assertPushed(MaterializeWorkCenterKpisJob::class, 1);
    Queue::assertPushed(MaterializeWorkCenterKpisJob::class, static fn (MaterializeWorkCenterKpisJob $job): bool => $job->work_center_id === $active->id);
});

it('stores the incomplete-data flag and does not read a figure cached under the old key', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $device = Modules\MES\Models\MachineDevice::factory()->create(['work_center_id' => $work_center->id, 'company_id' => $company->id]);
    Modules\MES\Models\MachineStateInterval::factory()->create(['company_id' => $company->id, 'device_id' => $device->id, 'work_center_id' => $work_center->id, 'state' => 'offline', 'started_at' => now()->startOfDay()->addHour(), 'ended_at' => now()->startOfDay()->addHours(2)]);
    Cache::put(sprintf('mes:kpi:%d:%s', $work_center->id, now()->toDateString()), 'stale', 60);
    Cache::put(sprintf('mes:kpi:v2:%d:%s', $work_center->id, now()->toDateString()), 'stale', 60);

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now()))->toBeNull();

    resolve(WorkCenterKpiMaterializer::class)->materialize($work_center, now());

    expect(resolve(WorkCenterKpiStore::class)->get($work_center->id, now())?->incomplete_data)->toBeTrue();
});
