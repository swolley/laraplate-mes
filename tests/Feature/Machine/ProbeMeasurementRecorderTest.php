<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Role;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\OutOfToleranceMeasured;
use Modules\MES\Events\ProbeMeasured;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Listeners\NotifyOutOfTolerance;
use Modules\MES\Listeners\ProbeMeasurementRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Notifications\OutOfToleranceNotification;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

/**
 * A probe on a work center, an operation, and a pending check of that operation whose plan has one characteristic
 * per entry of `$required_samples`, each with a signal.
 *
 * @param  list<int>  $required_samples
 * @return array{operation: ProductionOrderOperation, check: QualityCheck, device: MachineDevice, signals: list<MachineSignal>, characteristics: list<QualityPlanCharacteristic>}
 */
function probeRig(array $required_samples = [1]): array
{
    $work_center = WorkCenter::factory()->create();
    $order = ProductionOrder::factory()->create();
    $operation = ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'work_center_id' => $work_center->id]);
    $plan = QualityPlan::factory()->create();
    $device = MachineDevice::factory()->create(['work_center_id' => $work_center->id]);
    $characteristics = [];
    $signals = [];

    foreach ($required_samples as $index => $required) {
        $characteristic = QualityPlanCharacteristic::factory()->create(['quality_plan_id' => $plan->id, 'characteristic' => "dim{$index}", 'nominal' => 10, 'lower_limit' => 9, 'upper_limit' => 11, 'required_samples' => $required]);
        $characteristics[] = $characteristic;
        $signals[] = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => "dim{$index}", 'role' => SignalRole::Measurement->value, 'config' => [], 'quality_plan_characteristic_id' => $characteristic->id]);
    }

    $check = QualityCheck::factory()->create(['production_order_id' => $order->id, 'production_order_operation_id' => $operation->id, 'quality_plan_id' => $plan->id]);

    return ['operation' => $operation, 'check' => $check, 'device' => $device, 'signals' => $signals, 'characteristics' => $characteristics];
}

/**
 * @param  array<string, mixed>  $rig
 */
function probe(array $rig, int $signal_index, float $value, string $time, ?int $operation_id = null, array $context = []): void
{
    $signal = $rig['signals'][$signal_index];
    $device = $rig['device'];
    $ts = CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone'));
    $sample = new ResolvedSample($device, $signal, new NormalizedSample('d', $signal->key, $ts, $value, context: $context), $operation_id ?? $rig['operation']->id);

    resolve(ProbeMeasurementRecorder::class)->handle(new ProbeMeasured((int) $device->company_id, $device->id, (int) $device->work_center_id, [$sample]));
}

it('records a measurement on the pending check of its operation, with the limits of the characteristic', function (): void {
    $rig = probeRig();

    probe($rig, 0, 10.2, '08:00:00.250', context: ['serial' => 'SN-7']);

    $row = QualityCheckMeasurement::query()->sole();
    expect($row->quality_check_id)->toBe($rig['check']->id)
        ->and($row->characteristic)->toBe('dim0')
        ->and((float) $row->nominal)->toBe(10.0)
        ->and((float) $row->lower_limit)->toBe(9.0)
        ->and((float) $row->upper_limit)->toBe(11.0)
        ->and((float) $row->measured_value)->toBe(10.2)
        ->and($row->source)->toBe('machine')
        ->and($row->machine_signal_id)->toBe($rig['signals'][0]->id)
        ->and($row->quality_plan_characteristic_id)->toBe($rig['characteristics'][0]->id)
        ->and($row->serial)->toBe('SN-7')
        ->and($row->measured_at->format('H:i:s.v'))->toBe('08:00:00.250')
        ->and($row->is_within_limits)->toBeTrue()
        ->and(UnattributedMeasurement::query()->count())->toBe(0);
});

it('resolves the check on the sample that completes the required samples', function (): void {
    $rig = probeRig([3]);

    probe($rig, 0, 10, '08:00:00');
    probe($rig, 0, 10.5, '08:01:00');
    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Pending);

    probe($rig, 0, 9.5, '08:02:00');

    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed)
        ->and($rig['check']->fresh()->checked_at)->not->toBeNull();
});

it('waits for every characteristic of the plan', function (): void {
    $rig = probeRig([1, 2]);

    probe($rig, 0, 10, '08:00:00');
    probe($rig, 1, 10, '08:00:30');
    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Pending);

    probe($rig, 1, 10, '08:01:00');
    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed);
});

it('announces an out-of-limit value at once and fails the check on resolution with one non-conformance', function (): void {
    Event::fake([OutOfToleranceMeasured::class]);
    $rig = probeRig([2]);

    probe($rig, 0, 11.5, '08:00:00');

    Event::assertDispatched(OutOfToleranceMeasured::class, static fn (OutOfToleranceMeasured $event): bool => $event->quality_check_id === $rig['check']->id && $event->characteristic === 'dim0' && $event->value === 11.5 && $event->lower === 9.0 && $event->upper === 11.0);
    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Pending);

    probe($rig, 0, 10, '08:01:00');

    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Failed)
        ->and(NonConformance::query()->where('quality_check_id', $rig['check']->id)->count())->toBe(1);
});

it('treats a value exactly on a limit as within, and one hundredth over as out', function (): void {
    Event::fake([OutOfToleranceMeasured::class]);
    $rig = probeRig([2]);

    probe($rig, 0, 11.0, '08:00:00');
    probe($rig, 0, 9.0, '08:00:30');
    Event::assertNotDispatched(OutOfToleranceMeasured::class);

    probe($rig, 0, 11.01, '08:01:00');
    Event::assertDispatchedTimes(OutOfToleranceMeasured::class, 1);
});

it('opens a non-conformance for an out-of-limit value after resolution and keeps the status', function (): void {
    $rig = probeRig();
    probe($rig, 0, 10, '08:00:00');
    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed);

    probe($rig, 0, 12, '08:05:00');

    expect($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed)
        ->and(NonConformance::query()->where('quality_check_id', $rig['check']->id)->count())->toBe(1)
        ->and(QualityCheckMeasurement::query()->count())->toBe(2);
});

it('keeps a sample no check can take in the unattributed table, announcing it when out of limits', function (): void {
    Event::fake([OutOfToleranceMeasured::class]);
    $rig = probeRig();
    $other_operation = ProductionOrderOperation::factory()->create(['work_center_id' => $rig['device']->work_center_id]);

    probe($rig, 0, 12, '08:00:00', operation_id: $other_operation->id, context: ['serial' => 'SN-1']);

    $row = UnattributedMeasurement::query()->sole();
    expect($row->production_order_operation_id)->toBe($other_operation->id)
        ->and((float) $row->value)->toBe(12.0)
        ->and($row->serial)->toBe('SN-1')
        ->and(QualityCheckMeasurement::query()->count())->toBe(0);
    Event::assertDispatched(OutOfToleranceMeasured::class, static fn (OutOfToleranceMeasured $event): bool => $event->quality_check_id === null);
});

it('keeps a sample of a signal without characteristic, and one without operation, unattributed and never announces the first', function (): void {
    Event::fake([OutOfToleranceMeasured::class]);
    $rig = probeRig();
    // The signal rules require a characteristic for this role; one left without it (the characteristic deleted) must not break the pipeline.
    $loose = MachineSignal::withoutEvents(static fn (): MachineSignal => MachineSignal::factory()->create(['device_id' => $rig['device']->id, 'key' => 'loose', 'role' => SignalRole::Measurement->value, 'config' => [], 'quality_plan_characteristic_id' => null]));
    $rig['signals'][] = $loose;

    probe($rig, 1, 99, '08:00:00');
    $device = $rig['device'];
    $sample = new ResolvedSample($device, $rig['signals'][0], new NormalizedSample('d', 'dim0', CarbonImmutable::parse('2026-10-05 08:01:00', config()->string('app.timezone')), 10.0), null);
    resolve(ProbeMeasurementRecorder::class)->handle(new ProbeMeasured((int) $device->company_id, $device->id, (int) $device->work_center_id, [$sample]));

    expect(UnattributedMeasurement::query()->count())->toBe(2)
        ->and(QualityCheckMeasurement::query()->count())->toBe(0);
    Event::assertNotDispatched(OutOfToleranceMeasured::class);
});

it('stores one measurement, one event and one non-conformance when the same samples arrive twice', function (): void {
    Event::fake([OutOfToleranceMeasured::class]);
    $rig = probeRig();

    probe($rig, 0, 12, '08:00:00');
    probe($rig, 0, 12, '08:00:00');

    expect(QualityCheckMeasurement::query()->count())->toBe(1)
        ->and(NonConformance::query()->where('quality_check_id', $rig['check']->id)->count())->toBe(1);
    Event::assertDispatchedTimes(OutOfToleranceMeasured::class, 1);
});

it('produces the same rows through the whole pipeline when the message is reprocessed', function (): void {
    $rig = probeRig([2]);
    $payload = json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => 'probe-1', 'source_seq' => 1, 'sent_at' => '2026-10-05T06:00:00.000Z',
        'devices' => [['device' => $rig['device']->external_id, 'type' => 'data', 'samples' => [
            ['signal' => 'dim0', 'ts' => '2026-10-05T06:00:00.000Z', 'value' => 10.1, 'context' => ['operation_ref' => (string) $rig['operation']->id]],
            ['signal' => 'dim0', 'ts' => '2026-10-05T06:00:10.000Z', 'value' => 10.3, 'context' => ['operation_ref' => (string) $rig['operation']->id]],
        ]]],
    ], JSON_THROW_ON_ERROR);
    $message = MachineMessage::factory()->create(['source_id' => $rig['device']->source_id, 'message_id' => 'probe-1', 'payload' => $payload]);

    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id), 'handle']);
    $once = QualityCheckMeasurement::query()->orderBy('id')->get()->toArray();
    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id, true), 'handle']);

    expect(QualityCheckMeasurement::query()->orderBy('id')->get()->toArray())->toEqual($once)
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and(QualityCheckMeasurement::query()->count())->toBe(2)
        ->and($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed);
});

it('notifies the recipients holding the configured role', function (): void {
    Notification::fake();
    config(['mes.notifications.out_of_tolerance.recipients.roles' => ['quality']]);
    $user = user_class()::factory()->create();
    $user->assignRole(Role::findOrCreate('quality', 'web'));
    $rig = probeRig();

    new NotifyOutOfTolerance()->handle(new OutOfToleranceMeasured(
        company_id: (int) $rig['device']->company_id,
        quality_check_id: $rig['check']->id,
        signal_id: $rig['signals'][0]->id,
        characteristic: 'dim0',
        value: 12.0,
        lower: 9.0,
        upper: 11.0,
    ));

    Notification::assertSentTo($user, OutOfToleranceNotification::class);
});

it('attaches a measurement to a check that appeared while the measurement was being stored as waiting', function (): void {
    $rig = probeRig();
    $rig['check']->forceFill(['production_order_operation_id' => null])->saveQuietly();
    $created = false;
    DB::listen(function ($query) use (&$created, $rig): void {
        if (! $created && str_contains($query->sql, 'insert into "mes_machine_unattributed_measurements"')) {
            $created = true;
            $rig['check']->forceFill(['production_order_operation_id' => $rig['operation']->id])->saveQuietly();
        }
    });

    probe($rig, 0, 10.5, '08:00:00');

    expect(QualityCheckMeasurement::query()->where('quality_check_id', $rig['check']->id)->count())->toBe(1)
        ->and(UnattributedMeasurement::query()->sole()->assigned_at)->not->toBeNull()
        ->and($rig['check']->fresh()->status)->toBe(QualityCheckStatus::Passed);
});
