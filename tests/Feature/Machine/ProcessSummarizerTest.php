<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\ProcessValuesSampled;
use Modules\MES\Listeners\ProcessValueRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Machine\Process\ProcessSample as ProcessSampleData;
use Modules\MES\Machine\Process\ProcessValueStore;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\OperationProcessSummary;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\ProcessSample;
use Modules\MES\Services\LotTracingService;
use Modules\MES\Services\ProcessSummarizer;
use Modules\MES\Services\ProductionOrderOperationService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Carbon::setTestNow('2026-10-05 12:00:00');
    // The summaries are written by a queued job: run it in the process.
    config(['mes.queue.connection' => 'sync']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function summarySignal(array $config = []): MachineSignal
{
    return MachineSignal::factory()->create(['device_id' => MachineDevice::factory()->create()->id, 'key' => 'temp', 'role' => SignalRole::ProcessValue->value, 'config' => $config]);
}

function storeSummarySample(MachineSignal $signal, int $operation_id, string $time, float $value, SampleQuality $quality = SampleQuality::Good): void
{
    $device = $signal->device;
    resolve(ProcessValueStore::class)->write([new ProcessSampleData((int) $device->company_id, $signal->id, $device->id, (int) $device->work_center_id, $operation_id, CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone')), $value, $quality)]);
}

function summaryOperation(): ProductionOrderOperation
{
    return ProductionOrderOperation::factory()->create(['status' => ProductionOrderOperationStatus::InProgress->value, 'actual_start_at' => now()->subHour()]);
}

it('writes one summary per signal when an operation completes', function (): void {
    $ranged = summarySignal(['min' => 0, 'max' => 10]);
    $free = summarySignal();
    $operation = summaryOperation();
    foreach ([['08:00:00', 0.0], ['08:01:00', 5.0], ['08:02:00', 10.0], ['08:03:00', 11.0]] as [$time, $value]) {
        storeSummarySample($ranged, $operation->id, $time, $value);
    }
    storeSummarySample($ranged, $operation->id, '08:04:00', 500.0, SampleQuality::Bad);
    storeSummarySample($free, $operation->id, '08:00:30', 7.0);
    storeSummarySample($free, summaryOperation()->id, '08:00:40', 99.0);

    resolve(ProductionOrderOperationService::class)->complete($operation);

    $summaries = OperationProcessSummary::query()->where('production_order_operation_id', $operation->id)->get()->keyBy('signal_id');
    expect($summaries)->toHaveCount(2)
        ->and((float) $summaries[$ranged->id]->min)->toBe(0.0)
        ->and((float) $summaries[$ranged->id]->max)->toBe(11.0)
        ->and((float) $summaries[$ranged->id]->avg)->toBe(6.5)
        ->and($summaries[$ranged->id]->count)->toBe(4)
        ->and($summaries[$ranged->id]->out_of_range_count)->toBe(1)
        ->and($summaries[$ranged->id]->first_ts->format('H:i:s'))->toBe('08:00:00')
        ->and($summaries[$ranged->id]->last_ts->format('H:i:s'))->toBe('08:03:00')
        ->and($summaries[$free->id]->out_of_range_count)->toBe(0)
        ->and($summaries[$free->id]->count)->toBe(1);
});

it('writes no summary for an operation without samples', function (): void {
    $operation = summaryOperation();

    resolve(ProductionOrderOperationService::class)->complete($operation);

    expect(OperationProcessSummary::query()->count())->toBe(0);
});

it('updates the summary of a completed operation when late samples arrive, and again unchanged on a replay', function (): void {
    $signal = summarySignal();
    $device = $signal->device;
    $operation = summaryOperation();
    resolve(ProductionOrderOperationService::class)->complete($operation);
    $handle = static function (array $samples) use ($device): void {
        resolve(ProcessValueRecorder::class)->handle(new ProcessValuesSampled((int) $device->company_id, $device->id, (int) $device->work_center_id, $samples));
    };
    $sample = static fn (string $time, float $value): ResolvedSample => new ResolvedSample($device, $signal, new NormalizedSample('d', 'temp', CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone')), $value), $operation->id);

    $handle([$sample('08:00:00', 10.0)]);
    expect(OperationProcessSummary::query()->sole()->count)->toBe(1);

    $handle([$sample('08:00:00', 10.0), $sample('08:01:00', 20.0)]);
    $summary = OperationProcessSummary::query()->sole();
    expect($summary->count)->toBe(2)
        ->and((float) $summary->avg)->toBe(15.0);

    $handle([$sample('08:00:00', 10.0), $sample('08:01:00', 20.0)]);
    expect(OperationProcessSummary::query()->sole()->count)->toBe(2);
});

it('does not touch the summary of an operation that is still running', function (): void {
    $signal = summarySignal();
    $device = $signal->device;
    $operation = summaryOperation();

    resolve(ProcessValueRecorder::class)->handle(new ProcessValuesSampled((int) $device->company_id, $device->id, (int) $device->work_center_id, [
        new ResolvedSample($device, $signal, new NormalizedSample('d', 'temp', CarbonImmutable::parse('2026-10-05 08:00:00', config()->string('app.timezone')), 10.0), $operation->id),
    ]));

    expect(OperationProcessSummary::query()->count())->toBe(0);
});

it('never empties a summary when its raw samples have been pruned', function (): void {
    $signal = summarySignal();
    $operation = summaryOperation();
    storeSummarySample($signal, $operation->id, '08:00:00', 10.0);
    resolve(ProductionOrderOperationService::class)->complete($operation);
    ProcessSample::query()->delete();

    $written = resolve(ProcessSummarizer::class)->summarize($operation->id);

    expect($written)->toBe(0)
        ->and(OperationProcessSummary::query()->sole()->count)->toBe(1);
});

it('gives a lot the process summaries of the operations of its production order, and only those', function (): void {
    $signal = summarySignal();
    $operation = summaryOperation();
    storeSummarySample($signal, $operation->id, '08:00:00', 10.0);
    resolve(ProductionOrderOperationService::class)->complete($operation);
    $other = summaryOperation();
    storeSummarySample($signal, $other->id, '08:00:10', 20.0);
    resolve(ProductionOrderOperationService::class)->complete($other);
    $lot = LotNumber::factory()->create(['production_order_id' => $operation->production_order_id]);
    $without = LotNumber::factory()->create(['production_order_id' => ProductionOrder::factory()->create()->id]);

    $summaries = resolve(LotTracingService::class)->processSummaries($lot->id);

    expect($summaries)->toHaveCount(1)
        ->and($summaries->first()->production_order_operation_id)->toBe($operation->id)
        ->and(resolve(LotTracingService::class)->processSummaries($without->id))->toHaveCount(0);
});

it('never replaces a summary with one computed from fewer samples than it already counted', function (): void {
    $signal = summarySignal();
    $operation = summaryOperation();
    foreach (['08:00:00', '08:01:00', '08:02:00'] as $index => $time) {
        storeSummarySample($signal, $operation->id, $time, 10.0 + $index);
    }
    resolve(ProductionOrderOperationService::class)->complete($operation);
    expect(OperationProcessSummary::query()->sole()->count)->toBe(3);

    ProcessSample::query()->where('ts', '<', '2026-10-05 08:02:00')->delete();
    $written = resolve(ProcessSummarizer::class)->summarize($operation->id);

    $summary = OperationProcessSummary::query()->sole();
    expect($written)->toBe(0)
        ->and($summary->count)->toBe(3)
        ->and((float) $summary->min)->toBe(10.0);
});

it('queues the summary of a completed operation for new samples, and not at all for a replay', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $signal = summarySignal();
    $device = $signal->device;
    $operation = ProductionOrderOperation::factory()->create(['status' => ProductionOrderOperationStatus::Completed->value]);
    $samples = [new ResolvedSample($device, $signal, new NormalizedSample('d', 'temp', CarbonImmutable::parse('2026-10-05 08:00:00', config()->string('app.timezone')), 10.0), $operation->id)];
    $handle = static fn () => resolve(ProcessValueRecorder::class)->handle(new ProcessValuesSampled((int) $device->company_id, $device->id, (int) $device->work_center_id, $samples));

    $handle();
    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\SummarizeOperationProcessValuesJob::class, 1);

    // The uniqueness lock of the queued job is gone once it starts; a replay must still queue nothing.
    Illuminate\Support\Facades\Cache::flush();
    $handle();

    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\SummarizeOperationProcessValuesJob::class, 1);
});

it('queues the summary job when an operation completes', function (): void {
    Illuminate\Support\Facades\Queue::fake();

    resolve(ProductionOrderOperationService::class)->complete(summaryOperation());

    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\SummarizeOperationProcessValuesJob::class, 1);
});

it('lets the queued summary job be unique per operation until it starts', function (): void {
    $job = new Modules\MES\Jobs\SummarizeOperationProcessValuesJob(42);

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42');
});

it('does not fail the completion of an operation when the summary cannot be written', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $operation = summaryOperation();

    $completed = resolve(ProductionOrderOperationService::class)->complete($operation);

    expect($completed->status)->toBe(ProductionOrderOperationStatus::Completed);
});
