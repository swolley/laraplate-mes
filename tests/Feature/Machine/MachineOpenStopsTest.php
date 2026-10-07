<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Listeners\MachineStateRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Machine\MachineWatchdog;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Carbon::setTestNow('2026-10-05 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{device: MachineDevice, signal: MachineSignal}
 */
function stopRig(array $device = []): array
{
    $device = MachineDevice::factory()->create($device);
    $signal = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'state', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]);

    return ['device' => $device, 'signal' => $signal];
}

function stateInterval(MachineDevice $device, string $state, string $from, ?string $to = null): MachineStateInterval
{
    return MachineStateInterval::factory()->create([
        'company_id' => $device->company_id,
        'device_id' => $device->id,
        'work_center_id' => $device->work_center_id,
        'state' => $state,
        'started_at' => "2026-10-05 {$from}",
        'ended_at' => $to === null ? null : "2026-10-05 {$to}",
    ]);
}

function stopSample(array $rig, string $state, string $time): void
{
    $device = $rig['device'];
    $sample = new ResolvedSample($device, $rig['signal'], new NormalizedSample('d', 'state', CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone')), strtoupper($state)), null, MachineState::from($state));

    resolve(MachineStateRecorder::class)->handle(new MachineStateObserved((int) $device->company_id, $device->id, (int) $device->work_center_id, [$sample]));
}

it('opens the downtime of a stop that has been open longer than the threshold, once', function (): void {
    $rig = stopRig();
    stateInterval($rig['device'], 'running', '08:00:00', '11:50:00');
    stateInterval($rig['device'], 'fault', '11:50:00');

    $this->artisan('mes:machine-open-stops')->expectsOutputToContain('1')->assertSuccessful();
    $this->artisan('mes:machine-open-stops')->assertSuccessful();

    $downtime = Downtime::query()->sole();
    expect($downtime->source)->toBe(DowntimeSource::Machine)
        ->and($downtime->ended_at)->toBeNull()
        ->and($downtime->started_at->format('H:i:s'))->toBe('11:50:00');
});

it('leaves a stop younger than the threshold alone', function (): void {
    $rig = stopRig();
    stateInterval($rig['device'], 'fault', '11:59:30');

    $this->artisan('mes:machine-open-stops')->assertSuccessful();

    expect(Downtime::query()->count())->toBe(0);
});

it('skips the devices that are not active, and the intervals that are not downtime states', function (): void {
    $inactive = stopRig(['is_active' => false]);
    stateInterval($inactive['device'], 'fault', '11:00:00');
    $running = stopRig();
    stateInterval($running['device'], 'running', '11:00:00');

    $this->artisan('mes:machine-open-stops')->assertSuccessful();

    expect(Downtime::query()->count())->toBe(0);
});

it('closes the downtime when the machine leaves the state', function (): void {
    $rig = stopRig();
    stateInterval($rig['device'], 'fault', '11:50:00');
    $this->artisan('mes:machine-open-stops')->assertSuccessful();

    stopSample($rig, 'running', '11:55:00');

    $downtime = Downtime::query()->sole();
    expect($downtime->ended_at?->format('H:i:s'))->toBe('11:55:00')
        ->and((float) $downtime->duration_minutes)->toBe(5.0);
});

it('deletes the downtime a late sample shows to have been a micro-stop', function (): void {
    $rig = stopRig();
    stateInterval($rig['device'], 'fault', '11:50:00');
    $this->artisan('mes:machine-open-stops')->assertSuccessful();
    expect(Downtime::query()->count())->toBe(1);

    stopSample($rig, 'running', '11:50:30');

    expect(Downtime::query()->count())->toBe(0);
});

it('schedules the command every minute', function (): void {
    $event = collect(app(Schedule::class)->events())->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'mes:machine-open-stops'));

    expect($event?->expression)->toBe('* * * * *');
});

it('puts a silent device offline from the moment it was last heard, once', function (): void {
    $rig = stopRig(['last_seen_at' => '2026-10-05 11:55:00']);
    $rig['device']->source->update(['heartbeat_timeout_seconds' => 120]);
    stateInterval($rig['device'], 'running', '08:00:00');

    resolve(MachineWatchdog::class)->sweep();
    resolve(MachineWatchdog::class)->sweep();

    $intervals = MachineStateInterval::query()->where('device_id', $rig['device']->id)->orderBy('started_at')->get()->map(static fn ($i): array => [$i->state->value, $i->started_at->format('H:i:s'), $i->ended_at?->format('H:i:s')])->all();
    expect($intervals)->toBe([['running', '08:00:00', '11:55:00'], ['offline', '11:55:00', null]])
        ->and(Downtime::query()->count())->toBe(0);
});

it('does not invent an interval for a silent device that has no state signal', function (): void {
    $device = MachineDevice::factory()->create(['last_seen_at' => '2026-10-05 11:55:00']);
    $device->source->update(['heartbeat_timeout_seconds' => 120]);
    MachineSignal::factory()->create(['device_id' => $device->id, 'role' => SignalRole::GoodCount->value, 'config' => ['mode' => 'delta']]);

    resolve(MachineWatchdog::class)->sweep();

    expect(MachineStateInterval::query()->count())->toBe(0)
        ->and(MachineIncident::query()->where('type', MachineIncidentType::DeviceSilent->value)->count())->toBe(1);
});

it('closes the offline interval when the device is heard again', function (): void {
    $rig = stopRig(['last_seen_at' => '2026-10-05 11:55:00']);
    $rig['device']->source->update(['heartbeat_timeout_seconds' => 120]);
    resolve(MachineWatchdog::class)->sweep();
    expect(MachineStateInterval::query()->open()->count())->toBe(1);

    $rig['device']->update(['last_seen_at' => '2026-10-05 11:59:30']);
    resolve(MachineWatchdog::class)->sweep();

    $interval = MachineStateInterval::query()->sole();
    expect($interval->state)->toBe(MachineState::Offline)
        ->and($interval->ended_at?->format('H:i:s'))->toBe('11:59:30')
        ->and(MachineSource::query()->count())->toBe(1);
});
