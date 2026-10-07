<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Machine\MachineConnectivity;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Policies\MesModelPolicy;
use Modules\MES\Services\DowntimeService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

/**
 * A work center with an active device of an active source that has a state signal.
 *
 * @return array{device: MachineDevice, work_center: WorkCenter}
 */
function connectedWorkCenter(): array
{
    $device = MachineDevice::factory()->create();
    MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'state', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]);

    return ['device' => $device, 'work_center' => $device->workCenter];
}

function machineDowntime(MachineDevice $device, array $attributes = []): Downtime
{
    return Downtime::writingAsMachine(static fn (): Downtime => Downtime::factory()->create($attributes + [
        'company_id' => $device->company_id,
        'work_center_id' => $device->work_center_id,
        'source' => DowntimeSource::Machine->value,
        'machine_device_id' => $device->id,
        'started_at' => '2026-10-05 08:00:00',
        'ended_at' => '2026-10-05 08:10:00',
        'duration_minutes' => 10,
    ]));
}

it('knows a connected work center: an active device of an active source with a state signal', function (): void {
    $connected = connectedWorkCenter();
    $connectivity = resolve(MachineConnectivity::class);

    expect($connectivity->isConnected($connected['work_center']->id))->toBeTrue()
        ->and($connectivity->isConnected(WorkCenter::factory()->create()->id))->toBeFalse();

    $connected['device']->update(['is_active' => false]);
    expect($connectivity->isConnected($connected['work_center']->id))->toBeFalse();

    $connected['device']->update(['is_active' => true]);
    $connected['device']->source->update(['is_active' => false]);
    expect($connectivity->isConnected($connected['work_center']->id))->toBeFalse();

    $connected['device']->source->update(['is_active' => true]);
    $connected['device']->signals()->first()->delete();
    expect($connectivity->isConnected($connected['work_center']->id))->toBeFalse();
});

it('does not count a device that only has other signals', function (): void {
    $device = MachineDevice::factory()->create();
    MachineSignal::factory()->create(['device_id' => $device->id, 'role' => SignalRole::GoodCount->value, 'config' => ['mode' => 'delta']]);

    expect(resolve(MachineConnectivity::class)->isConnected($device->work_center_id))->toBeFalse();
});

it('refuses a manual downtime on a connected work center, through the service and through the model, and allows other work centers', function (): void {
    $connected = connectedWorkCenter();
    $other = WorkCenter::factory()->create();

    expect(fn () => resolve(DowntimeService::class)->open($connected['work_center'], DowntimeCause::Breakdown))->toThrow(DomainException::class)
        ->and(fn () => Downtime::factory()->create(['company_id' => $connected['work_center']->company_id, 'work_center_id' => $connected['work_center']->id]))->toThrow(ValidationException::class);

    expect(resolve(DowntimeService::class)->open($other, DowntimeCause::Breakdown)->exists)->toBeTrue()
        ->and(Downtime::query()->where('work_center_id', $connected['work_center']->id)->count())->toBe(0);
});

it('lets the machine path write a machine downtime and refuses it anywhere else', function (): void {
    $connected = connectedWorkCenter();

    expect(fn () => Downtime::factory()->create(['company_id' => $connected['device']->company_id, 'work_center_id' => $connected['device']->work_center_id, 'source' => DowntimeSource::Machine->value, 'machine_device_id' => $connected['device']->id]))
        ->toThrow(ValidationException::class);

    expect(machineDowntime($connected['device'])->exists)->toBeTrue();
});

it('lets the operator edit the cause and notes of a machine downtime but not its times', function (): void {
    $downtime = machineDowntime(connectedWorkCenter()['device']);

    $downtime->update(['cause' => DowntimeCause::Breakdown->value, 'notes' => 'jammed']);
    expect($downtime->fresh()->cause)->toBe(DowntimeCause::Breakdown);

    foreach (['started_at' => '2026-10-05 07:00:00', 'ended_at' => '2026-10-05 09:00:00', 'duration_minutes' => 5] as $column => $value) {
        expect(fn () => $downtime->fresh()->update([$column => $value]))->toThrow(ValidationException::class);
    }

    expect(fn () => $downtime->fresh()->update(['source' => DowntimeSource::Manual->value]))->toThrow(ValidationException::class);
});

it('lets the machine path change the times, and switches off again afterwards, even after an exception', function (): void {
    $downtime = machineDowntime(connectedWorkCenter()['device']);

    Downtime::writingAsMachine(static fn () => $downtime->fresh()->update(['ended_at' => '2026-10-05 08:20:00', 'duration_minutes' => 20]));
    expect($downtime->fresh()->ended_at?->format('H:i'))->toBe('08:20');

    try {
        Downtime::writingAsMachine(static function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // the flag has to be off again
    }

    expect(fn () => $downtime->fresh()->update(['ended_at' => '2026-10-05 08:30:00']))->toThrow(ValidationException::class);
});

it('does not let a machine downtime be closed by hand', function (): void {
    $downtime = machineDowntime(connectedWorkCenter()['device'], ['ended_at' => null, 'duration_minutes' => null]);

    expect(fn () => resolve(DowntimeService::class)->close($downtime))->toThrow(DomainException::class);
});

it('hides the close action of a machine downtime and keeps it for a manual open one', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
    $policy = resolve(MesModelPolicy::class);
    $machine = machineDowntime(connectedWorkCenter()['device'], ['ended_at' => null, 'duration_minutes' => null]);
    $manual = Downtime::factory()->create();

    expect($policy->close($user, $machine))->toBeFalse()
        ->and($policy->close($user, $manual))->toBeTrue();
});

it('refuses a second device with a state signal on a work center', function (): void {
    $connected = connectedWorkCenter();
    $second = MachineDevice::factory()->create(['source_id' => $connected['device']->source_id, 'work_center_id' => $connected['work_center']->id]);

    expect(fn () => MachineSignal::factory()->create(['device_id' => $second->id, 'key' => 'state', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]))
        ->toThrow(ValidationException::class);

    // Other signals of the second device, and a state signal on another work center, are fine.
    expect(MachineSignal::factory()->create(['device_id' => $second->id, 'key' => 'parts', 'role' => SignalRole::GoodCount->value, 'config' => ['mode' => 'delta']])->exists)->toBeTrue();
    $elsewhere = MachineDevice::factory()->create();
    expect(MachineSignal::factory()->create(['device_id' => $elsewhere->id, 'key' => 'state', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]])->exists)->toBeTrue();
});

it('lets the same device have its state signal edited', function (): void {
    $connected = connectedWorkCenter();
    $signal = $connected['device']->signals()->first();

    $signal->update(['unit' => 'x']);

    expect($signal->fresh()->unit)->toBe('x')
        ->and(MachineSource::query()->count())->toBe(1);
});
