<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Listeners\ProcessValueRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\ProcessAggregate;
use Modules\MES\Models\ProcessSample;
use Modules\MES\Models\ProductionOrderOperation;
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
function processRig(): array
{
    $device = MachineDevice::factory()->create();

    return ['device' => $device, 'signal' => MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'temp', 'role' => SignalRole::ProcessValue->value, 'config' => []])];
}

/**
 * @param  array{device: MachineDevice, signal: MachineSignal}  $rig
 * @param  list<ResolvedSample>  $samples
 */
function handleProcess(array $rig, array $samples): void
{
    $device = $rig['device'];
    resolve(ProcessValueRecorder::class)->handle(new ProcessValuesSampled((int) $device->company_id, $device->id, (int) $device->work_center_id, $samples));
}

function processSampleOf(array $rig, string $time, int|float|string $value, SampleQuality $quality = SampleQuality::Good, ?int $operation_id = null, ?MachineSignal $signal = null): ResolvedSample
{
    $signal ??= $rig['signal'];

    return new ResolvedSample($rig['device'], $signal, new NormalizedSample('d', $signal->key, CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone')), $value, $quality), $operation_id);
}

it('stores the samples with their operation and quality and marks their minutes', function (): void {
    $rig = processRig();
    $operation = ProductionOrderOperation::factory()->create();

    handleProcess($rig, [processSampleOf($rig, '08:00:10', 71.5, operation_id: $operation->id), processSampleOf($rig, '08:00:40', 72, SampleQuality::Uncertain), processSampleOf($rig, '08:01:10', 73)]);

    $rows = ProcessSample::query()->orderBy('ts')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->production_order_operation_id)->toBe($operation->id)
        ->and((float) $rows[0]->value)->toBe(71.5)
        ->and($rows[1]->quality)->toBe(SampleQuality::Uncertain)
        ->and(DB::table('mes_process_dirty_buckets')->count())->toBe(2);
});

it('ignores reference samples and values that are not numbers', function (): void {
    $rig = processRig();
    $reference = MachineSignal::factory()->create(['device_id' => $rig['device']->id, 'key' => 'order', 'role' => SignalRole::OrderReference->value, 'config' => []]);

    handleProcess($rig, [processSampleOf($rig, '08:00:10', 'PO-1', signal: $reference), processSampleOf($rig, '08:00:20', 'hot'), processSampleOf($rig, '08:00:30', '42.5')]);

    expect(ProcessSample::query()->count())->toBe(1)
        ->and((float) ProcessSample::query()->sole()->value)->toBe(42.5);
});

it('stores the same rows and marks when the same samples arrive twice', function (): void {
    $rig = processRig();
    $samples = [processSampleOf($rig, '08:00:10', 10), processSampleOf($rig, '08:00:20', 20)];

    handleProcess($rig, $samples);
    $once = [ProcessSample::query()->orderBy('id')->get()->toArray(), DB::table('mes_process_dirty_buckets')->orderBy('id')->get()->toArray()];
    handleProcess($rig, $samples);

    expect([ProcessSample::query()->orderBy('id')->get()->toArray(), DB::table('mes_process_dirty_buckets')->orderBy('id')->get()->toArray()])->toEqual($once);
});

it('does not store a sample older than the raw retention', function (): void {
    $rig = processRig();
    config(['mes.machine.raw_retention_days' => 1]);

    handleProcess($rig, [
        new ResolvedSample($rig['device'], $rig['signal'], new NormalizedSample('d', 'temp', CarbonImmutable::parse('2026-10-03 08:00:00', config()->string('app.timezone')), 10)),
        processSampleOf($rig, '08:00:10', 11),
    ]);

    expect(ProcessSample::query()->count())->toBe(1)
        ->and((float) ProcessSample::query()->sole()->value)->toBe(11.0);
});

it('keeps a bad sample out of the aggregate after the rollup', function (): void {
    $rig = processRig();
    handleProcess($rig, [processSampleOf($rig, '08:00:10', 10), processSampleOf($rig, '08:00:20', 500, SampleQuality::Bad)]);

    $this->artisan('mes:machine-rollup')->assertSuccessful();

    expect(ProcessSample::query()->count())->toBe(2)
        ->and((float) ProcessAggregate::query()->where('resolution', '1m')->sole()->max)->toBe(10.0);
});

it('writes a big message with a number of queries that does not grow with the samples', function (): void {
    $rig = processRig();
    $build = function (int $count) use ($rig): array {
        $samples = [];

        for ($i = 0; $i < $count; $i++) {
            $samples[] = new ResolvedSample($rig['device'], $rig['signal'], new NormalizedSample('d', 'temp', CarbonImmutable::parse('2026-10-05 06:00:00', config()->string('app.timezone'))->addSeconds($i * 3 + ($count > 500 ? 100000 : 0)), (float) $i));
        }

        return $samples;
    };

    DB::enableQueryLog();
    handleProcess($rig, $build(500));
    $small = count(DB::getQueryLog());
    DB::flushQueryLog();
    handleProcess($rig, $build(5000));
    $big = count(DB::getQueryLog());

    expect(ProcessSample::query()->count())->toBe(5500)
        ->and($big)->toBeLessThanOrEqual($small + 30)
        ->and($big)->toBeLessThan(60);
});

it('produces the same rows through the whole pipeline when the message is reprocessed', function (): void {
    $rig = processRig();
    $payload = json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => 'proc-1', 'source_seq' => 1, 'sent_at' => '2026-10-05T06:00:00.000Z',
        'devices' => [['device' => $rig['device']->external_id, 'type' => 'data', 'samples' => [
            ['signal' => 'temp', 'ts' => '2026-10-05T06:00:00.000Z', 'value' => 70.5],
            ['signal' => 'temp', 'ts' => '2026-10-05T06:00:30.000Z', 'value' => 71.5],
        ]]],
    ], JSON_THROW_ON_ERROR);
    $message = MachineMessage::factory()->create(['source_id' => $rig['device']->source_id, 'message_id' => 'proc-1', 'payload' => $payload]);

    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id), 'handle']);
    $once = ProcessSample::query()->orderBy('id')->get()->toArray();
    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id, true), 'handle']);

    expect(ProcessSample::query()->orderBy('id')->get()->toArray())->toEqual($once)
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and(ProcessSample::query()->count())->toBe(2);
});

it('rebuilds the marked buckets from the rollup command, which is scheduled every minute without overlapping', function (): void {
    $rig = processRig();
    handleProcess($rig, [processSampleOf($rig, '08:00:10', 10)]);

    $this->artisan('mes:machine-rollup')->expectsOutputToContain('1')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'mes:machine-rollup'));
    expect(ProcessAggregate::query()->where('resolution', '1m')->count())->toBe(1)
        ->and($event?->expression)->toBe('* * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

it('prunes by the two retentions from the prune command, which is scheduled daily', function (): void {
    $rig = processRig();
    config(['mes.machine.raw_retention_days' => 1, 'mes.machine.minute_aggregate_retention_days' => 2]);
    ProcessSample::factory()->create(['signal_id' => $rig['signal']->id, 'device_id' => $rig['device']->id, 'work_center_id' => $rig['device']->work_center_id, 'ts' => '2026-10-03 08:00:00']);
    ProcessSample::factory()->create(['signal_id' => $rig['signal']->id, 'device_id' => $rig['device']->id, 'work_center_id' => $rig['device']->work_center_id, 'ts' => '2026-10-05 08:00:00']);
    ProcessAggregate::factory()->create(['signal_id' => $rig['signal']->id, 'resolution' => '1m', 'bucket_start' => '2026-10-01 08:00:00']);
    ProcessAggregate::factory()->create(['signal_id' => $rig['signal']->id, 'resolution' => '1m', 'bucket_start' => '2026-10-05 08:00:00']);
    ProcessAggregate::factory()->create(['signal_id' => $rig['signal']->id, 'resolution' => '1h', 'bucket_start' => '2026-10-01 08:00:00']);

    $this->artisan('mes:machine-prune-process-values')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'mes:machine-prune-process-values'));
    expect(ProcessSample::query()->count())->toBe(1)
        ->and(ProcessAggregate::query()->where('resolution', '1m')->count())->toBe(1)
        ->and(ProcessAggregate::query()->where('resolution', '1h')->count())->toBe(1)
        ->and($event?->expression)->toBe('0 0 * * *');
});
