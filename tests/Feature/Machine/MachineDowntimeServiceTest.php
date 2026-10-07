<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\DowntimeClosed;
use Modules\MES\Events\DowntimeOpened;
use Modules\MES\Machine\States\DowntimeCauseResolver;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

function deviceWithAlarmMap(array $map): MachineDevice
{
    $device = MachineDevice::factory()->create();
    MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'alarm', 'role' => SignalRole::Alarm->value, 'config' => ['map' => $map]]);

    return $device;
}

it('takes the cause from the alarm map first', function (): void {
    $device = deviceWithAlarmMap(['E17' => 'breakdown', 'E20' => 'material_shortage']);
    $resolver = resolve(DowntimeCauseResolver::class);

    expect($resolver->resolve($device, MachineState::Fault, 'E17'))->toBe(DowntimeCause::Breakdown)
        ->and($resolver->resolve($device, MachineState::Setup, 'E20'))->toBe(DowntimeCause::MaterialShortage);
});

it('falls back to the state default, then to unclassified', function (): void {
    $device = deviceWithAlarmMap(['E17' => 'breakdown']);
    $resolver = resolve(DowntimeCauseResolver::class);

    expect($resolver->resolve($device, MachineState::Setup, 'E99'))->toBe(DowntimeCause::Setup)
        ->and($resolver->resolve($device, MachineState::Maintenance, null))->toBe(DowntimeCause::PlannedMaintenance)
        ->and($resolver->resolve($device, MachineState::Fault, 'E99'))->toBe(DowntimeCause::Unclassified)
        ->and($resolver->resolve($device, MachineState::Stopped, null))->toBe(DowntimeCause::Unclassified)
        ->and($resolver->resolve($device, MachineState::Idle, null))->toBe(DowntimeCause::Unclassified);
});

it('ignores an alarm map entry that is not a downtime cause, and a device without an alarm signal', function (): void {
    $device = deviceWithAlarmMap(['E1' => 'breakdown']);
    // The signal rules would refuse this map; the resolver still has to cope with data that got in another way.
    DB::table('mes_machine_signals')->where('device_id', $device->id)->update(['config' => json_encode(['map' => ['E1' => 'gremlins']])]);
    $bare = MachineDevice::factory()->create();
    $resolver = resolve(DowntimeCauseResolver::class);

    expect($resolver->resolve($device, MachineState::Fault, 'E1'))->toBe(DowntimeCause::Unclassified)
        ->and($resolver->resolve($bare, MachineState::Fault, 'E1'))->toBe(DowntimeCause::Unclassified);
});

it('opens a machine downtime once, for the work center of the device', function (): void {
    Event::fake([DowntimeOpened::class]);
    $device = MachineDevice::factory()->create();
    $service = resolve(DowntimeService::class);
    $at = Carbon::parse('2026-10-05 08:00:00.250');

    $first = $service->openFromMachine($device, DowntimeCause::Breakdown, $at, 'E17');
    $again = $service->openFromMachine($device, DowntimeCause::Setup, $at, 'E18');

    expect($again->id)->toBe($first->id)
        ->and(Downtime::query()->count())->toBe(1)
        ->and($first->source)->toBe(DowntimeSource::Machine)
        ->and($first->machine_device_id)->toBe($device->id)
        ->and($first->work_center_id)->toBe($device->work_center_id)
        ->and($first->alarm_code)->toBe('E17')
        ->and($first->cause)->toBe(DowntimeCause::Breakdown)
        ->and($first->ended_at)->toBeNull()
        ->and($first->fresh()->started_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.250');
    Event::assertDispatchedTimes(DowntimeOpened::class, 1);
});

it('closes a machine downtime with its duration, once', function (): void {
    Event::fake([DowntimeClosed::class]);
    $device = MachineDevice::factory()->create();
    $service = resolve(DowntimeService::class);
    $downtime = $service->openFromMachine($device, DowntimeCause::Breakdown, Carbon::parse('2026-10-05 08:00:00.000'));

    $closed = $service->closeFromMachine($downtime, Carbon::parse('2026-10-05 08:01:30.500'));
    $service->closeFromMachine($closed, Carbon::parse('2026-10-05 08:01:30.500'));

    expect($closed->fresh()->ended_at?->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:01:30.500')
        ->and((float) $closed->fresh()->duration_minutes)->toBe(1.5083);
    Event::assertDispatchedTimes(DowntimeClosed::class, 1);
});

it('moves the end of an already closed machine downtime without announcing a second close', function (): void {
    Event::fake([DowntimeClosed::class]);
    $device = MachineDevice::factory()->create();
    $service = resolve(DowntimeService::class);
    $downtime = $service->openFromMachine($device, DowntimeCause::Breakdown, Carbon::parse('2026-10-05 08:00:00'));
    $service->closeFromMachine($downtime, Carbon::parse('2026-10-05 08:10:00'));

    $service->closeFromMachine($downtime->fresh(), Carbon::parse('2026-10-05 08:04:00'));

    expect($downtime->fresh()->ended_at?->format('H:i'))->toBe('08:04')
        ->and((float) $downtime->fresh()->duration_minutes)->toBe(4.0);
    Event::assertDispatchedTimes(DowntimeClosed::class, 1);
});

it('does not apply the one-open-downtime rule of manual downtimes to the machine path', function (): void {
    $device = MachineDevice::factory()->create();
    $service = resolve(DowntimeService::class);
    $service->openFromMachine($device, DowntimeCause::Breakdown, Carbon::parse('2026-10-05 08:00:00'));

    expect($service->openFromMachine($device, DowntimeCause::Setup, Carbon::parse('2026-10-05 09:00:00'))->exists)->toBeTrue()
        ->and(Downtime::query()->count())->toBe(2);
});
