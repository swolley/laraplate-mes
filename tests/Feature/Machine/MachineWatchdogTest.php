<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Role;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Events\MachineIncidentRecorded;
use Modules\MES\Listeners\NotifyMachineIncident;
use Modules\MES\Machine\MachineIncidentRecorder;
use Modules\MES\Machine\MachineWatchdog;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Notifications\MachineIncidentNotification;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Carbon::setTestNow('2026-10-05 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function deviceSeen(string $seen_at, array $source = ['heartbeat_timeout_seconds' => 120], array $device = []): MachineDevice
{
    return MachineDevice::factory()->create($device + [
        'source_id' => MachineSource::factory()->create($source)->id,
        'last_seen_at' => $seen_at,
    ]);
}

it('opens one incident for a device silent beyond the timeout, and not a second time', function (): void {
    $device = deviceSeen('2026-10-05 11:55:00');
    $watchdog = resolve(MachineWatchdog::class);

    expect($watchdog->sweep())->toBe(1)
        ->and($watchdog->sweep())->toBe(0);

    $incident = MachineIncident::query()->sole();
    expect($incident->type)->toBe(MachineIncidentType::DeviceSilent)
        ->and($incident->device_id)->toBe($device->id)
        ->and($incident->detail)->toBe(['device' => $device->external_id, 'silent_seconds' => 300]);
});

it('resolves the incident once the device is heard again', function (): void {
    $device = deviceSeen('2026-10-05 11:55:00');
    $watchdog = resolve(MachineWatchdog::class);
    $watchdog->sweep();

    $device->update(['last_seen_at' => '2026-10-05 11:59:30']);
    $watchdog->sweep();

    expect(MachineIncident::query()->sole()->resolved_at)->not->toBeNull();
});

it('leaves alone devices that are within their timeout, inactive, or of an inactive source', function (): void {
    deviceSeen('2026-10-05 11:59:00');
    deviceSeen('2026-10-05 11:00:00', device: ['is_active' => false]);
    deviceSeen('2026-10-05 11:00:00', source: ['is_active' => false]);

    expect(resolve(MachineWatchdog::class)->sweep())->toBe(0);
});

it('uses the timeout of each source', function (): void {
    deviceSeen('2026-10-05 11:55:00', ['heartbeat_timeout_seconds' => 600]);
    $short = deviceSeen('2026-10-05 11:55:00', ['heartbeat_timeout_seconds' => 60]);

    expect(resolve(MachineWatchdog::class)->sweep())->toBe(1)
        ->and(MachineIncident::query()->sole()->device_id)->toBe($short->id);
});

it('counts a device never heard from from the time it was created', function (): void {
    $old = MachineDevice::factory()->create(['last_seen_at' => null, 'created_at' => '2026-10-05 11:00:00']);
    MachineDevice::factory()->create(['last_seen_at' => null, 'created_at' => '2026-10-05 11:59:00']);

    expect(resolve(MachineWatchdog::class)->sweep())->toBe(1)
        ->and(MachineIncident::query()->sole()->device_id)->toBe($old->id);
});

it('notifies the configured role of an incident, and nobody else', function (): void {
    Notification::fake();
    config(['mes.notifications.machine_incident.recipients.roles' => ['plant_admin']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('plant_admin', 'web'));
    user_class()::factory()->create();
    $incident = resolve(MachineIncidentRecorder::class)->record(MachineSource::factory()->create(), MachineIncidentType::SeqGap, ['expected' => 2, 'received' => 5]);

    new NotifyMachineIncident()->handle(new MachineIncidentRecorded((int) $incident->company_id, $incident->id));

    Notification::assertSentTo($user, MachineIncidentNotification::class, static fn (MachineIncidentNotification $notification): bool => $notification->toArray($user)['type'] === 'seq_gap'
        && $notification->toArray($user)['detail'] === ['expected' => 2, 'received' => 5]);
    Notification::assertCount(1);
});

it('notifies when an incident is recorded', function (): void {
    Notification::fake();
    config(['mes.queue.connection' => 'sync', 'mes.notifications.machine_incident.recipients.roles' => ['plant_admin']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('plant_admin', 'web'));

    resolve(MachineIncidentRecorder::class)->record(MachineSource::factory()->create(), MachineIncidentType::ClockSkew);

    Notification::assertSentTo($user, MachineIncidentNotification::class);
});

it('schedules the watchdog every minute and the inbox pruning daily', function (): void {
    $events = collect(app(Schedule::class)->events());
    $watchdog = $events->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'mes:machine-watchdog'));
    $prune = $events->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'MachineMessage'));

    expect($watchdog?->expression)->toBe('* * * * *')
        ->and($prune?->expression)->toBe('0 0 * * *')
        ->and(MachineMessage::class)->toContain('MachineMessage');
});

it('queues again a message left pending, and leaves a fresh one alone', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $stale = MachineMessage::factory()->create(['received_at' => '2026-10-05 11:50:00']);
    MachineMessage::factory()->create(['received_at' => '2026-10-05 11:59:00']);
    MachineMessage::factory()->processed()->create(['received_at' => '2026-10-05 11:00:00']);

    resolve(MachineWatchdog::class)->sweep();

    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\ProcessMachineMessageJob::class, 1);
    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\ProcessMachineMessageJob::class, static fn ($job): bool => $job->machine_message_id === $stale->id);
});

it('notifies a source and incident type at most once in five minutes', function (): void {
    Notification::fake();
    config(['mes.notifications.machine_incident.recipients.roles' => ['plant_admin']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('plant_admin', 'web'));
    $source = MachineSource::factory()->create();
    $recorder = resolve(MachineIncidentRecorder::class);
    $listener = new NotifyMachineIncident();

    foreach (range(1, 3) as $ignored) {
        $incident = $recorder->record($source, MachineIncidentType::MessageFailed, ['n' => $ignored]);
        $listener->handle(new MachineIncidentRecorded((int) $incident->company_id, $incident->id));
    }

    Notification::assertSentToTimes($user, MachineIncidentNotification::class, 1);
});

it('opens one bridge_down incident per active mqtt source when the heartbeat is stale, and not again', function (): void {
    Illuminate\Support\Facades\Cache::put(Modules\MES\Machine\Mqtt\MachineBridge::HEARTBEAT_KEY, now()->subSeconds(120)->getTimestamp(), 600);
    $a = MachineSource::factory()->mqtt()->create();
    $b = MachineSource::factory()->mqtt()->create();
    MachineSource::factory()->create();
    MachineSource::factory()->mqtt()->inactive()->create();
    $watchdog = resolve(MachineWatchdog::class);

    expect($watchdog->sweep())->toBe(2)
        ->and($watchdog->sweep())->toBe(0);

    $incidents = MachineIncident::query()->where('type', MachineIncidentType::BridgeDown->value)->get();
    expect($incidents->pluck('source_id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($incidents->first()->detail)->toBe(['heartbeat_age_seconds' => 120]);
});

it('opens bridge_down when there is no heartbeat at all', function (): void {
    MachineSource::factory()->mqtt()->create();

    resolve(MachineWatchdog::class)->sweep();

    expect(MachineIncident::query()->where('type', MachineIncidentType::BridgeDown->value)->sole()->detail)->toBe(['heartbeat_age_seconds' => null]);
});

it('resolves bridge_down when the heartbeat is fresh again', function (): void {
    $source = MachineSource::factory()->mqtt()->create();
    $watchdog = resolve(MachineWatchdog::class);
    $watchdog->sweep();

    Illuminate\Support\Facades\Cache::put(Modules\MES\Machine\Mqtt\MachineBridge::HEARTBEAT_KEY, now()->subSeconds(5)->getTimestamp(), 600);
    $watchdog->sweep();

    expect(MachineIncident::query()->where('source_id', $source->id)->where('type', MachineIncidentType::BridgeDown->value)->sole()->resolved_at)->not->toBeNull();
});

it('records nothing about the bridge while no mqtt source is active', function (): void {
    MachineSource::factory()->create();

    resolve(MachineWatchdog::class)->sweep();

    expect(MachineIncident::query()->where('type', MachineIncidentType::BridgeDown->value)->count())->toBe(0);
});
