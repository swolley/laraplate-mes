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
    // Downtime times keep milliseconds, so the clock must not move between the writes and the reads.
    $this->freezeTime();
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);

    $service = resolve(OeeCalculatorService::class);
    $full = $service->availability($work_center->id, now()->subDay(), now()->addDay(), 480.0);

    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => now()->subHours(2),
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

it('counts only the part of a downtime that falls inside the window', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $from = now()->startOfDay();
    $to = now()->endOfDay();

    // Started 3 hours before the window, ended 1 hour into it: only 60 minutes count.
    Downtime::factory()->create([
        'company_id' => $company->id,
        'work_center_id' => $work_center->id,
        'cause' => DowntimeCause::Breakdown->value,
        'started_at' => $from->copy()->subHours(3),
        'ended_at' => $from->copy()->addHour(),
        'duration_minutes' => 240,
    ]);

    expect(resolve(OeeCalculatorService::class)->availability($work_center->id, $from, $to, 480.0))->toBe(420.0 / 480.0);
});

/**
 * @return array{0: WorkCenter, 1: Carbon\CarbonImmutable}
 */
function oeeConnectedWorkCenter(): array
{
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $device = Modules\MES\Models\MachineDevice::factory()->create(['work_center_id' => $work_center->id, 'company_id' => $company->id]);
    Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'state', 'role' => Modules\MES\Enums\SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]);

    return [$work_center, Carbon\CarbonImmutable::parse('2026-10-05 08:00:00')];
}

function oeeMachineDowntime(WorkCenter $work_center, DowntimeCause $cause, string $from, string $to): void
{
    $device = Modules\MES\Models\MachineDevice::query()->where('work_center_id', $work_center->id)->firstOrFail();
    Downtime::writingAsMachine(static fn () => Downtime::factory()->create([
        'company_id' => $work_center->company_id,
        'work_center_id' => $work_center->id,
        'source' => Modules\MES\Enums\DowntimeSource::Machine->value,
        'machine_device_id' => $device->id,
        'cause' => $cause->value,
        'started_at' => $from,
        'ended_at' => $to,
    ]));
}

it('measures a connected work center against its calendar time, planned maintenance left out of the busy time', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $to = $day->addMinutes(480);
    $service = resolve(OeeCalculatorService::class);

    expect($service->availability($work_center->id, $day, $to, 480.0))->toBe(1.0);

    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 09:00:00', '2026-10-05 10:00:00');
    expect($service->availability($work_center->id, $day, $to, 480.0))->toBe(420.0 / 480.0);

    oeeMachineDowntime($work_center, DowntimeCause::PlannedMaintenance, '2026-10-05 11:00:00', '2026-10-05 13:00:00');
    expect(round($service->availability($work_center->id, $day, $to, 480.0), 4))->toBe(round(300.0 / 360.0, 4));
});

it('keeps the planned-time formula for a work center without a machine', function (): void {
    $company = MesTestHelpers::makeCompany();
    $work_center = WorkCenter::factory()->create(['company_id' => $company->id]);
    $day = Carbon\CarbonImmutable::parse('2026-10-05 08:00:00');
    Downtime::factory()->create(['company_id' => $company->id, 'work_center_id' => $work_center->id, 'cause' => DowntimeCause::Breakdown->value, 'started_at' => '2026-10-05 09:00:00', 'ended_at' => '2026-10-05 10:00:00']);
    Downtime::factory()->create(['company_id' => $company->id, 'work_center_id' => $work_center->id, 'cause' => DowntimeCause::PlannedMaintenance->value, 'started_at' => '2026-10-05 11:00:00', 'ended_at' => '2026-10-05 13:00:00']);

    expect(resolve(OeeCalculatorService::class)->availability($work_center->id, $day, $day->addMinutes(480), 480.0))->toBe(420.0 / 480.0);
});

it('counts only the part of a breakdown inside the window, and is fully available when maintenance fills the calendar', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $service = resolve(OeeCalculatorService::class);
    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 07:00:00', '2026-10-05 09:00:00');

    expect($service->availability($work_center->id, $day, $day->addMinutes(480), 480.0))->toBe(420.0 / 480.0);

    oeeMachineDowntime($work_center, DowntimeCause::PlannedMaintenance, '2026-10-05 08:00:00', '2026-10-05 16:00:00');
    expect($service->availability($work_center->id, $day, $day->addHours(8), 480.0))->toBe(1.0);
});

it('flags offline stretches as incomplete data without counting them as downtime', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $device = Modules\MES\Models\MachineDevice::query()->where('work_center_id', $work_center->id)->firstOrFail();
    Modules\MES\Models\MachineStateInterval::factory()->create(['company_id' => $work_center->company_id, 'device_id' => $device->id, 'work_center_id' => $work_center->id, 'state' => 'offline', 'started_at' => '2026-10-05 09:00:00', 'ended_at' => '2026-10-05 10:00:00']);
    $service = resolve(OeeCalculatorService::class);

    expect($service->hasIncompleteData($work_center->id, $day, $day->addHours(8)))->toBeTrue()
        ->and($service->hasIncompleteData($work_center->id, $day->addHours(2), $day->addHours(8)))->toBeFalse()
        ->and($service->availability($work_center->id, $day, $day->addMinutes(480), 480.0))->toBe(1.0);
});

it('does not count the hours a connected machine stands still outside the working calendar', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    oeeMachineDowntime($work_center, DowntimeCause::Unclassified, '2026-10-04 16:00:00', '2026-10-05 08:00:00');
    oeeMachineDowntime($work_center, DowntimeCause::Unclassified, '2026-10-05 16:00:00', '2026-10-06 08:00:00');

    $kpis = resolve(Modules\MES\Services\WorkCenterKpiMaterializer::class)->materialize($work_center, $day);

    expect($kpis->availability)->toBe(1.0);

    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 09:00:00', '2026-10-05 10:00:00');

    expect(resolve(Modules\MES\Services\WorkCenterKpiMaterializer::class)->materialize($work_center, $day)->availability)->toBe(420.0 / 480.0);
});

function oeeCountRow(WorkCenter $work_center, string $time, array $quantities, ?int $operation_id = null): void
{
    Modules\MES\Models\MachineCount::factory()->create($quantities + [
        'work_center_id' => $work_center->id,
        'production_order_operation_id' => $operation_id,
        'ts' => "2026-10-05 {$time}",
    ]);
}

it('computes performance from the counted pieces and the ideal cycle time over the run time', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 09:00:00', '2026-10-05 10:00:00');
    $operation = Modules\MES\Models\ProductionOrderOperation::factory()->create(['work_center_id' => $work_center->id, 'cycle_time_minutes' => 0.5]);
    oeeCountRow($work_center, '10:30:00', ['total' => 400], $operation->id);
    oeeCountRow($work_center, '12:00:00', ['total' => 200], $operation->id);
    oeeCountRow($work_center, '16:00:00', ['total' => 999], $operation->id); // outside the window

    $performance = resolve(OeeCalculatorService::class)->performance($work_center->id, $day, $day->addHours(8));

    expect(round($performance, 6))->toBe(round(300.0 / 420.0, 6));
});

it('uses the ideal cycle of the work center for counts nobody attributed, and good plus scrap when no total is sent', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $work_center->update(['capacity_per_hour' => 120]);
    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 09:00:00', '2026-10-05 10:00:00');
    oeeCountRow($work_center, '10:30:00', ['good' => 570]);
    oeeCountRow($work_center, '10:30:00', ['scrap' => 30]);
    $service = resolve(OeeCalculatorService::class);

    expect(round($service->performance($work_center->id, $day, $day->addHours(8)), 6))->toBe(round(300.0 / 420.0, 6))
        ->and(round($service->quality($work_center->id, $day, $day->addHours(8)), 6))->toBe(0.95);
});

it('keeps performance and quality in range with no run time, or no good pieces', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $service = resolve(OeeCalculatorService::class);
    oeeCountRow($work_center, '10:00:00', ['scrap' => 10]);

    expect($service->quality($work_center->id, $day, $day->addHours(8)))->toBe(0.0)
        ->and($service->performance($work_center->id, $day, $day->addHours(8)))->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);

    oeeMachineDowntime($work_center, DowntimeCause::PlannedMaintenance, '2026-10-05 08:00:00', '2026-10-05 16:00:00');

    expect($service->performance($work_center->id, $day, $day->addHours(8)))->toBe(1.0);
});

it('keeps the order-based formulas for a work center that has no count rows in the window', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    oeeCountRow($work_center, '10:00:00', ['good' => 5]);
    $service = resolve(OeeCalculatorService::class);

    expect($service->quality($work_center->id, $day->addDay(), $day->addDays(2)))->toBe(1.0)
        ->and($service->performance($work_center->id, $day->addDay(), $day->addDays(2)))->toBe(1.0);
});

it('computes quality per device, so a device without a total counter is not mixed into another one\'s total', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $operation = Modules\MES\Models\ProductionOrderOperation::factory()->create(['work_center_id' => $work_center->id]);
    oeeCountRow($work_center, '10:00:00', ['good' => 90, 'total' => 100], $operation->id);
    oeeCountRow($work_center, '10:00:00', ['good' => 50], $operation->id);
    oeeCountRow($work_center, '10:00:00', ['scrap' => 50], $operation->id);

    // Device A: 90 good of 100. Device B (two signals, no total): 50 good of 100. Together 140 of 200.
    expect(round(resolve(OeeCalculatorService::class)->quality($work_center->id, $day, $day->addHours(8)), 4))->toBe(0.7);
});

it('derives good pieces from total minus scrap in quality when the device sends no good counter', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $device = Modules\MES\Models\MachineDevice::factory()->create(['work_center_id' => $work_center->id]);
    $total = Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 't', 'role' => Modules\MES\Enums\SignalRole::TotalCount->value, 'config' => ['mode' => 'delta']]);
    $scrap = Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 's', 'role' => Modules\MES\Enums\SignalRole::ScrapCount->value, 'config' => ['mode' => 'delta']]);
    foreach ([[$total, ['total' => 100]], [$scrap, ['scrap' => 5]]] as [$signal, $quantities]) {
        Modules\MES\Models\MachineCount::factory()->create($quantities + ['signal_id' => $signal->id, 'device_id' => $device->id, 'work_center_id' => $work_center->id, 'ts' => '2026-10-05 10:00:00']);
    }

    expect(round(resolve(OeeCalculatorService::class)->quality($work_center->id, $day, $day->addHours(8)), 4))->toBe(0.95);
});

it('falls back to the order-based formulas when the counts in the window are all zero', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    oeeCountRow($work_center, '10:00:00', ['good' => 0, 'raw_value' => 500]);
    $service = resolve(OeeCalculatorService::class);

    expect($service->performance($work_center->id, $day, $day->addHours(8)))->toBe(1.0)
        ->and($service->quality($work_center->id, $day, $day->addHours(8)))->toBe(1.0);
});

it('uses the ideal cycle of the work center for an operation without a cycle time', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    $work_center->update(['capacity_per_hour' => 120]);
    oeeMachineDowntime($work_center, DowntimeCause::Breakdown, '2026-10-05 09:00:00', '2026-10-05 10:00:00');
    $operation = Modules\MES\Models\ProductionOrderOperation::factory()->create(['work_center_id' => $work_center->id, 'cycle_time_minutes' => 0]);
    oeeCountRow($work_center, '10:30:00', ['total' => 600], $operation->id);

    expect(round(resolve(OeeCalculatorService::class)->performance($work_center->id, $day, $day->addHours(8)), 6))->toBe(round(300.0 / 420.0, 6));
});

it('reads the counts once for performance and quality together', function (): void {
    [$work_center, $day] = oeeConnectedWorkCenter();
    oeeCountRow($work_center, '10:30:00', ['good' => 570]);
    oeeCountRow($work_center, '10:30:00', ['scrap' => 30]);
    $service = resolve(OeeCalculatorService::class);
    DB::enableQueryLog();

    $both = $service->performanceAndQuality($work_center->id, $day, $day->addHours(8));

    $reads = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'mes_machine_counts'))->count();
    expect($reads)->toBe(1)
        ->and($both['quality'])->toBe($service->quality($work_center->id, $day, $day->addHours(8)))
        ->and($both['performance'])->toBe($service->performance($work_center->id, $day, $day->addHours(8)));
});
