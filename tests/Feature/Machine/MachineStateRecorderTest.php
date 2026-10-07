<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\DowntimeCause;
use Modules\MES\Enums\DowntimeSource;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\MachineStateObserved;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Listeners\MachineStateRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineStateInterval;
use Modules\MES\Models\WorkCenter;
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
 * A device with a state signal and an alarm signal, on a work center with the default threshold (60 s)
 * and downtime states.
 *
 * @return array{device: MachineDevice, state: MachineSignal, alarm: MachineSignal}
 */
function stateRig(array $work_center = []): array
{
    $device = MachineDevice::factory()->create(['work_center_id' => WorkCenter::factory()->create($work_center)->id]);
    $state = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'state', 'role' => SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running', 'FAULT' => 'fault', 'STOP' => 'stopped', 'SETUP' => 'setup', 'MAINT' => 'maintenance', 'OFF' => 'offline']]]);
    $alarm = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'alarm', 'role' => SignalRole::Alarm->value, 'config' => ['map' => ['E17' => 'breakdown']]]);

    return ['device' => $device, 'state' => $state, 'alarm' => $alarm];
}

/**
 * Feeds state ("state@HH:MM:SS") and alarm ("alarm:CODE@HH:MM:SS") samples of 2026-10-05, in the order given.
 *
 * @param  array<string, mixed>  $rig
 * @param  list<string>  $samples
 */
function feedStates(array $rig, array $samples): void
{
    $resolved = [];

    foreach ($samples as $sample) {
        [$what, $time] = explode('@', $sample);
        $ts = CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone'));

        if (str_starts_with($what, 'alarm:')) {
            $code = substr($what, 6);
            $resolved[] = new ResolvedSample($rig['device'], $rig['alarm'], new NormalizedSample('d', 'alarm', $ts, $code), null, null, $code);

            continue;
        }

        $state = MachineState::from($what);
        $resolved[] = new ResolvedSample($rig['device'], $rig['state'], new NormalizedSample('d', 'state', $ts, strtoupper($what), SampleQuality::Good), null, $state);
    }

    $device = $rig['device'];
    resolve(MachineStateRecorder::class)->handle(new MachineStateObserved((int) $device->company_id, $device->id, (int) $device->work_center_id, $resolved));
}

/**
 * @return list<array{string, string, ?string}>
 */
function intervalsOf(MachineDevice $device): array
{
    return MachineStateInterval::query()->where('device_id', $device->id)->orderBy('started_at')->get()
        ->map(static fn (MachineStateInterval $interval): array => [$interval->state->value, $interval->started_at->format('H:i:s'), $interval->ended_at?->format('H:i:s')])
        ->all();
}

function snapshotOf(): array
{
    return [MachineStateInterval::query()->orderBy('id')->get()->toArray(), Downtime::query()->orderBy('id')->get()->toArray()];
}

it('opens an interval, ignores the same state again and closes the interval at a change', function (): void {
    $rig = stateRig();

    feedStates($rig, ['running@08:00:00', 'running@08:05:00', 'fault@08:10:00']);

    expect(intervalsOf($rig['device']))->toBe([['running', '08:00:00', '08:10:00'], ['fault', '08:10:00', null]]);
});

it('derives a downtime only from a stop longer than the threshold', function (): void {
    $rig = stateRig();

    feedStates($rig, ['running@08:00:00', 'fault@08:10:00', 'running@08:11:00', 'fault@09:00:00', 'running@09:01:01']);

    $downtimes = Downtime::query()->orderBy('started_at')->get();
    expect($downtimes)->toHaveCount(1)
        ->and($downtimes[0]->started_at->format('H:i:s'))->toBe('09:00:00')
        ->and($downtimes[0]->ended_at?->format('H:i:s'))->toBe('09:01:01')
        ->and($downtimes[0]->source)->toBe(DowntimeSource::Machine)
        ->and($downtimes[0]->machine_device_id)->toBe($rig['device']->id)
        ->and($downtimes[0]->work_center_id)->toBe($rig['device']->work_center_id);
});

it('derives the downtime of a stop that is still open once it has lasted past the threshold', function (): void {
    $rig = stateRig();

    feedStates($rig, ['running@11:55:00', 'fault@11:59:30']);
    expect(Downtime::query()->count())->toBe(0);

    feedStates(stateRig(), ['running@11:50:00', 'fault@11:57:00']);
    $open = Downtime::query()->sole();

    expect($open->ended_at)->toBeNull()
        ->and($open->started_at->format('H:i:s'))->toBe('11:57:00');
});

it('takes the cause from the alarm code, then from the state, then unclassified', function (): void {
    $rig = stateRig();

    feedStates($rig, ['running@08:00:00', 'fault@08:10:00', 'alarm:E17@08:10:01', 'alarm:E18@08:10:02', 'running@08:20:00']);
    feedStates($rig, ['fault@08:30:00', 'alarm:E99@08:30:01', 'running@08:40:00']);
    feedStates($rig, ['setup@08:50:00', 'running@09:00:00']);
    feedStates($rig, ['maintenance@09:10:00', 'running@09:30:00']);
    feedStates($rig, ['stopped@09:40:00', 'running@10:00:00']);

    $by_start = Downtime::query()->orderBy('started_at')->get()->keyBy(static fn (Downtime $d): string => $d->started_at->format('H:i'));
    expect($by_start['08:10']->cause)->toBe(DowntimeCause::Breakdown)
        ->and($by_start['08:10']->alarm_code)->toBe('E17')
        ->and($by_start['08:30']->cause)->toBe(DowntimeCause::Unclassified)
        ->and($by_start['08:30']->alarm_code)->toBe('E99')
        ->and($by_start['08:50']->cause)->toBe(DowntimeCause::Setup)
        ->and($by_start['09:10']->cause)->toBe(DowntimeCause::PlannedMaintenance)
        ->and($by_start['09:40']->cause)->toBe(DowntimeCause::Unclassified);
});

it('keeps Offline as an interval and never derives a downtime from it', function (): void {
    $rig = stateRig();

    feedStates($rig, ['running@08:00:00', 'offline@08:10:00', 'running@09:00:00']);

    expect(intervalsOf($rig['device']))->toBe([['running', '08:00:00', '08:10:00'], ['offline', '08:10:00', '09:00:00'], ['running', '09:00:00', null]])
        ->and(Downtime::query()->count())->toBe(0);
});

it('a late sample splits its interval and keeps the operator\'s cause', function (): void {
    $rig = stateRig();
    feedStates($rig, ['running@08:00:00', 'stopped@08:10:00', 'running@08:15:00']);
    $downtime = Downtime::query()->sole();
    $downtime->update(['cause' => DowntimeCause::Breakdown->value, 'notes' => 'jammed feeder']);

    feedStates($rig, ['running@08:12:00']);

    expect(intervalsOf($rig['device']))->toBe([['running', '08:00:00', '08:10:00'], ['stopped', '08:10:00', '08:12:00'], ['running', '08:12:00', null]]);
    $fresh = $downtime->fresh();
    expect(Downtime::query()->count())->toBe(1)
        ->and($fresh->ended_at?->format('H:i:s'))->toBe('08:12:00')
        ->and((float) $fresh->duration_minutes)->toBe(2.0)
        ->and($fresh->cause)->toBe(DowntimeCause::Breakdown)
        ->and($fresh->notes)->toBe('jammed feeder');
});

it('deletes the downtime of a stop a late sample cuts down to the threshold or below', function (): void {
    $rig = stateRig();
    feedStates($rig, ['running@08:00:00', 'stopped@08:10:00', 'running@08:15:00']);
    expect(Downtime::query()->count())->toBe(1);

    feedStates($rig, ['running@08:10:30']);

    expect(Downtime::query()->count())->toBe(0)
        ->and(intervalsOf($rig['device']))->toBe([['running', '08:00:00', '08:10:00'], ['stopped', '08:10:00', '08:10:30'], ['running', '08:10:30', null]]);
});

it('puts a late sample before the first interval in front of it, and merges it when the state is the same', function (): void {
    $rig = stateRig();
    feedStates($rig, ['running@08:10:00']);

    feedStates($rig, ['fault@08:00:00']);
    expect(intervalsOf($rig['device']))->toBe([['fault', '08:00:00', '08:10:00'], ['running', '08:10:00', null]]);

    $other = stateRig();
    feedStates($other, ['running@08:10:00', 'running@08:00:00']);
    expect(intervalsOf($other['device']))->toBe([['running', '08:00:00', null]]);
});

it('ignores a late sample whose state is the one it falls in', function (): void {
    $rig = stateRig();
    feedStates($rig, ['running@08:00:00', 'stopped@08:10:00', 'running@08:15:00']);
    $before = snapshotOf();

    feedStates($rig, ['stopped@08:12:00']);

    expect(snapshotOf())->toEqual($before);
});

it('keeps the first state written for an instant', function (): void {
    $rig = stateRig();
    feedStates($rig, ['running@08:00:00', 'fault@08:10:00']);

    feedStates($rig, ['stopped@08:10:00']);

    expect(intervalsOf($rig['device']))->toBe([['running', '08:00:00', '08:10:00'], ['fault', '08:10:00', null]]);
});

it('processing the same events twice leaves the intervals and the downtimes identical', function (): void {
    $rig = stateRig();
    $samples = ['running@08:00:00', 'fault@08:10:00', 'alarm:E17@08:10:01', 'running@08:20:00', 'stopped@08:30:00', 'running@08:32:00'];

    feedStates($rig, $samples);
    $once = snapshotOf();
    feedStates($rig, $samples);

    expect(snapshotOf())->toEqual($once);
});

it('the whole pipeline leaves the same data when a message is reprocessed', function (): void {
    $rig = stateRig();
    $payload = json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => 'states-1', 'source_seq' => 1, 'sent_at' => '2026-10-05T06:00:00.000Z',
        'devices' => [['device' => $rig['device']->external_id, 'type' => 'data', 'samples' => [
            ['signal' => 'state', 'ts' => '2026-10-05T06:00:00.000Z', 'value' => 'RUN'],
            ['signal' => 'state', 'ts' => '2026-10-05T06:10:00.000Z', 'value' => 'FAULT'],
            ['signal' => 'alarm', 'ts' => '2026-10-05T06:10:01.000Z', 'value' => 'E17'],
            ['signal' => 'state', 'ts' => '2026-10-05T06:20:00.000Z', 'value' => 'RUN'],
        ]]],
    ], JSON_THROW_ON_ERROR);
    $message = MachineMessage::factory()->create(['source_id' => $rig['device']->source_id, 'message_id' => 'states-1', 'payload' => $payload]);

    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id), 'handle']);
    $once = snapshotOf();
    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id, true), 'handle']);

    expect(snapshotOf())->toEqual($once)
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and(Downtime::query()->sole()->cause)->toBe(DowntimeCause::Breakdown)
        ->and(MachineStateInterval::query()->count())->toBe(3);
});

it('stores the sample time as the same instant whatever its timezone', function (): void {
    $rig = stateRig();
    $utc = CarbonImmutable::parse('2026-10-05 10:00:00.250', 'UTC');
    $device = $rig['device'];
    $sample = new ResolvedSample($device, $rig['state'], new NormalizedSample('d', 'state', $utc, 'RUN'), null, MachineState::Running);

    resolve(MachineStateRecorder::class)->handle(new MachineStateObserved((int) $device->company_id, $device->id, (int) $device->work_center_id, [$sample]));

    expect(MachineStateInterval::query()->sole()->started_at->getTimestamp())->toBe($utc->getTimestamp());
});

it('derives a downtime for any stop when the threshold is zero', function (): void {
    $rig = stateRig(['micro_stop_threshold_seconds' => 0]);

    feedStates($rig, ['running@08:00:00', 'fault@08:10:00', 'running@08:10:01']);

    expect(Downtime::query()->count())->toBe(1);
});

it('ignores states the work center does not treat as downtime', function (): void {
    $rig = stateRig(['downtime_states' => ['fault']]);

    feedStates($rig, ['running@08:00:00', 'setup@08:10:00', 'running@08:30:00', 'fault@08:40:00', 'running@08:50:00']);

    $downtime = Downtime::query()->sole();
    expect($downtime->started_at->format('H:i'))->toBe('08:40');
});
