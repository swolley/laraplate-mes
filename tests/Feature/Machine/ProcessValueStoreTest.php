<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Machine\Process\ProcessSample as ProcessSampleData;
use Modules\MES\Machine\Process\ProcessValueStore;
use Modules\MES\Models\MachineDevice;
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

function processSignal(array $config = []): MachineSignal
{
    $device = MachineDevice::factory()->create();

    return MachineSignal::factory()->create(['device_id' => $device->id, 'role' => SignalRole::ProcessValue->value, 'config' => $config]);
}

function sampleOf(MachineSignal $signal, string $time, float $value, SampleQuality $quality = SampleQuality::Good, ?int $operation_id = null): ProcessSampleData
{
    $device = $signal->device;

    return new ProcessSampleData((int) $device->company_id, $signal->id, $device->id, (int) $device->work_center_id, $operation_id, CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone')), $value, $quality);
}

function store(): ProcessValueStore
{
    return resolve(ProcessValueStore::class);
}

it('stores a batch once and ignores a repeat', function (): void {
    $signal = processSignal();
    $batch = [sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:00:20', 30), sampleOf($signal, '08:00:50', 20)];

    expect(store()->write($batch))->toBe(3)
        ->and(store()->write($batch))->toBe(0)
        ->and(ProcessSample::query()->count())->toBe(3);
});

it('rolls a dirty minute up into its aggregate and clears the mark', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:00:20', 30), sampleOf($signal, '08:00:50', 20)]);

    expect(store()->rollup())->toBe(1);

    $aggregate = ProcessAggregate::query()->where('resolution', '1m')->sole();
    expect($aggregate->bucket_start->format('H:i:s'))->toBe('08:00:00')
        ->and((float) $aggregate->min)->toBe(10.0)
        ->and((float) $aggregate->max)->toBe(30.0)
        ->and((float) $aggregate->avg)->toBe(20.0)
        ->and((float) $aggregate->last)->toBe(20.0)
        ->and($aggregate->count)->toBe(3)
        ->and(DB::table('mes_process_dirty_buckets')->count())->toBe(0)
        ->and(store()->rollup())->toBe(0);
});

it('absorbs a late sample into a closed minute', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:00:20', 30)]);
    store()->rollup();

    store()->write([sampleOf($signal, '08:00:55', 40)]);
    store()->rollup();

    $aggregate = ProcessAggregate::query()->where('resolution', '1m')->sole();
    expect((float) $aggregate->max)->toBe(40.0)
        ->and((float) $aggregate->last)->toBe(40.0)
        ->and($aggregate->count)->toBe(3);
});

it('keeps a mark that was set while the rollup was running', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10)]);
    $remarked = false;
    DB::listen(function ($query) use (&$remarked, $signal): void {
        if (! $remarked && str_contains($query->sql, 'mes_process_aggregates')) {
            $remarked = true;
            Carbon::setTestNow('2026-10-05 12:00:30');
            store()->write([sampleOf($signal, '08:00:55', 40)]);
        }
    });

    store()->rollup();
    expect(DB::table('mes_process_dirty_buckets')->count())->toBe(1);

    store()->rollup();
    expect((float) ProcessAggregate::query()->where('resolution', '1m')->sole()->max)->toBe(40.0)
        ->and(DB::table('mes_process_dirty_buckets')->count())->toBe(0);
});

it('leaves bad samples out, and drops the aggregate of a minute that only has bad ones', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:00:20', 99, SampleQuality::Bad), sampleOf($signal, '08:01:10', 77, SampleQuality::Bad)]);
    ProcessAggregate::factory()->create(['signal_id' => $signal->id, 'resolution' => '1m', 'bucket_start' => '2026-10-05 08:01:00']);

    store()->rollup();

    $aggregates = ProcessAggregate::query()->where('resolution', '1m')->get();
    expect($aggregates)->toHaveCount(1)
        ->and((float) $aggregates->first()->max)->toBe(10.0)
        ->and($aggregates->first()->count)->toBe(1);
});

it('builds the hour as the count-weighted combination of its minutes', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:01:10', 20), sampleOf($signal, '08:01:20', 20), sampleOf($signal, '08:01:30', 20)]);

    store()->rollup();

    $hour = ProcessAggregate::query()->where('resolution', '1h')->sole();
    expect($hour->bucket_start->format('H:i:s'))->toBe('08:00:00')
        ->and((float) $hour->min)->toBe(10.0)
        ->and((float) $hour->max)->toBe(20.0)
        ->and((float) $hour->avg)->toBe(17.5)
        ->and((float) $hour->last)->toBe(20.0)
        ->and($hour->count)->toBe(4);
});

it('never replaces an aggregate with an empty one when its raw samples have been pruned', function (): void {
    $signal = processSignal();
    ProcessAggregate::factory()->create(['signal_id' => $signal->id, 'resolution' => '1m', 'bucket_start' => '2026-10-05 08:00:00', 'count' => 3, 'max' => 9]);
    DB::table('mes_process_dirty_buckets')->insert(['company_id' => $signal->company_id, 'signal_id' => $signal->id, 'bucket_start' => '2026-10-05 08:00:00', 'marked_at' => '2026-10-05 12:00:00']);

    store()->rollup();

    expect((float) ProcessAggregate::query()->where('resolution', '1m')->sole()->max)->toBe(9.0)
        ->and(DB::table('mes_process_dirty_buckets')->count())->toBe(0);
});

it('returns the aggregates of a resolution and range in order', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '08:01:10', 20), sampleOf($signal, '08:02:10', 30)]);
    store()->rollup();

    $rows = store()->aggregates($signal->id, CarbonImmutable::parse('2026-10-05 08:01:00', config()->string('app.timezone')), CarbonImmutable::parse('2026-10-05 08:03:00', config()->string('app.timezone')), '1m');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->bucket_start->format('H:i'))->toBe('08:01')
        ->and($rows[0]->avg)->toBe(20.0)
        ->and($rows[1]->bucket_start->format('H:i'))->toBe('08:02')
        ->and(store()->aggregates($signal->id, CarbonImmutable::parse('2026-10-05 08:00:00', config()->string('app.timezone')), CarbonImmutable::parse('2026-10-05 09:00:00', config()->string('app.timezone')), '1h'))->toHaveCount(1);
});

it('prunes raw samples and minute aggregates older than the limit, and keeps the hours', function (): void {
    $signal = processSignal();
    store()->write([sampleOf($signal, '08:00:10', 10), sampleOf($signal, '09:00:10', 20)]);
    store()->rollup();
    $limit = CarbonImmutable::parse('2026-10-05 08:30:00', config()->string('app.timezone'));

    expect(store()->prune($limit))->toBe(1)
        ->and(store()->pruneAggregates($limit))->toBe(1)
        ->and(ProcessSample::query()->count())->toBe(1)
        ->and(ProcessAggregate::query()->where('resolution', '1m')->count())->toBe(1)
        ->and(ProcessAggregate::query()->where('resolution', '1h')->count())->toBe(2);
});

it('computes the statistics of an operation per signal, bounds inclusive, bad samples out', function (): void {
    $ranged = processSignal();
    $free = processSignal();
    $operation = ProductionOrderOperation::factory()->create();
    $other = ProductionOrderOperation::factory()->create();
    store()->write([
        sampleOf($ranged, '08:00:00', 0, operation_id: $operation->id),
        sampleOf($ranged, '08:01:00', 5, operation_id: $operation->id),
        sampleOf($ranged, '08:02:00', 10, operation_id: $operation->id),
        sampleOf($ranged, '08:03:00', 11, operation_id: $operation->id),
        sampleOf($ranged, '08:04:00', 100, SampleQuality::Bad, $operation->id),
        sampleOf($ranged, '08:05:00', 500, operation_id: $other->id),
        sampleOf($free, '08:00:30', 7, operation_id: $operation->id),
    ]);

    $stats = collect(store()->operationStatistics($operation->id, [$ranged->id => ['min' => 0.0, 'max' => 10.0]]))->keyBy('signal_id');

    expect($stats)->toHaveCount(2)
        ->and($stats[$ranged->id]->min)->toBe(0.0)
        ->and($stats[$ranged->id]->max)->toBe(11.0)
        ->and($stats[$ranged->id]->avg)->toBe(6.5)
        ->and($stats[$ranged->id]->count)->toBe(4)
        ->and($stats[$ranged->id]->out_of_range_count)->toBe(1)
        ->and($stats[$ranged->id]->first_ts->format('H:i:s'))->toBe('08:00:00')
        ->and($stats[$ranged->id]->last_ts->format('H:i:s'))->toBe('08:03:00')
        ->and($stats[$free->id]->out_of_range_count)->toBe(0)
        ->and(store()->operationStatistics(ProductionOrderOperation::factory()->create()->id, []))->toBe([]);
});

it('refuses an unknown store driver and names the known ones', function (): void {
    config(['mes.machine.process_store' => 'nope']);
    app()->forgetInstance(ProcessValueStore::class);

    expect(fn () => resolve(ProcessValueStore::class))->toThrow(RuntimeException::class, 'database');
});
