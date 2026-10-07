<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\MachineState;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

it('gives a work center a micro-stop threshold and downtime states', function (): void {
    $work_center = WorkCenter::factory()->create();

    expect($work_center->micro_stop_threshold_seconds)->toBe(60)
        ->and($work_center->downtime_states)->toBe(['fault', 'stopped', 'setup', 'maintenance'])
        ->and($work_center->isDowntimeState(MachineState::Fault))->toBeTrue()
        ->and($work_center->isDowntimeState(MachineState::Running))->toBeFalse()
        ->and($work_center->isDowntimeState(MachineState::Offline))->toBeFalse();
});

it('refuses a downtime state that is not a machine state, and a negative threshold', function (array $invalid): void {
    expect(fn () => WorkCenter::factory()->create($invalid))->toThrow(ValidationException::class);
})->with([
    'unknown state' => [['downtime_states' => ['fault', 'exploding']]],
    'negative threshold' => [['micro_stop_threshold_seconds' => -1]],
]);

it('keeps a limited list of downtime states and a zero threshold', function (): void {
    $work_center = WorkCenter::factory()->create(['downtime_states' => ['fault'], 'micro_stop_threshold_seconds' => 0]);

    expect($work_center->fresh()->downtime_states)->toBe(['fault'])
        ->and($work_center->fresh()->micro_stop_threshold_seconds)->toBe(0)
        ->and($work_center->isDowntimeState(MachineState::Setup))->toBeFalse();
});

it('reads the default downtime states of a work center stored without them', function (): void {
    $work_center = WorkCenter::factory()->create();
    DB::table('mes_work_centers')->where('id', $work_center->id)->update(['downtime_states' => null]);

    expect($work_center->fresh()->isDowntimeState(MachineState::Stopped))->toBeTrue();
});

it('defaults a downtime to manual and accepts the unclassified cause', function (): void {
    $downtime = Downtime::factory()->create(['cause' => DowntimeCause::Unclassified->value]);

    expect($downtime->fresh()->source)->toBe(DowntimeSource::Manual)
        ->and($downtime->fresh()->cause)->toBe(DowntimeCause::Unclassified)
        ->and($downtime->fresh()->machine_device_id)->toBeNull()
        ->and($downtime->fresh()->alarm_code)->toBeNull();
});

it('stores the device and the alarm of a machine downtime, with millisecond times', function (): void {
    $device = MachineDevice::factory()->create();

    $downtime = Downtime::factory()->create([
        'company_id' => $device->company_id,
        'work_center_id' => $device->work_center_id,
        'source' => DowntimeSource::Machine->value,
        'machine_device_id' => $device->id,
        'alarm_code' => 'E17',
        'started_at' => '2026-10-05 08:00:00.123',
        'ended_at' => '2026-10-05 08:05:30.456',
    ]);

    $fresh = $downtime->fresh();
    expect($fresh->source)->toBe(DowntimeSource::Machine)
        ->and($fresh->machine_device_id)->toBe($device->id)
        ->and($fresh->alarm_code)->toBe('E17')
        ->and($fresh->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.123')
        ->and($fresh->ended_at?->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:05:30.456')
        ->and($fresh->device?->id)->toBe($device->id);
});

it('refuses two downtimes of one work center with the same start', function (): void {
    $first = Downtime::factory()->create(['started_at' => '2026-10-05 08:00:00']);

    expect(fn () => Downtime::factory()->create(['company_id' => $first->company_id, 'work_center_id' => $first->work_center_id, 'started_at' => '2026-10-05 08:00:00']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('stores a state interval with millisecond times and refuses a duplicate start on a device', function (): void {
    $interval = MachineStateInterval::factory()->create(['started_at' => '2026-10-05 08:00:00.250', 'ended_at' => null]);

    expect($interval->fresh()->state)->toBe(MachineState::Running)
        ->and($interval->fresh()->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.250')
        ->and(MachineStateInterval::query()->open()->count())->toBe(1);

    expect(fn () => MachineStateInterval::factory()->create(['device_id' => $interval->device_id, 'company_id' => $interval->company_id, 'work_center_id' => $interval->work_center_id, 'started_at' => '2026-10-05 08:00:00.250']))
        ->toThrow(UniqueConstraintViolationException::class);
});
