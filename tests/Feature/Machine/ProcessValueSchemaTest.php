<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\MES\Models\OperationProcessSummary;
use Modules\MES\Models\ProcessAggregate;
use Modules\MES\Models\ProcessSample;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

it('stores a raw sample with milliseconds and refuses a second one of the signal at the same moment', function (): void {
    $sample = ProcessSample::factory()->create(['ts' => '2026-10-05 08:00:00.125', 'value' => 71.5]);

    $fresh = ProcessSample::query()->findOrFail($sample->id);
    expect($fresh->ts->format('Y-m-d H:i:s.v'))->toBe('2026-10-05 08:00:00.125')
        ->and((float) $fresh->value)->toBe(71.5)
        ->and($fresh->quality)->toBe(Modules\MES\Enums\SampleQuality::Good);

    expect(fn () => ProcessSample::factory()->create(['signal_id' => $sample->signal_id, 'device_id' => $sample->device_id, 'work_center_id' => $sample->work_center_id, 'ts' => '2026-10-05 08:00:00.125']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a duplicate aggregate bucket of a signal and resolution', function (): void {
    $aggregate = ProcessAggregate::factory()->create(['resolution' => '1m', 'bucket_start' => '2026-10-05 08:00:00']);

    expect(fn () => ProcessAggregate::factory()->create(['signal_id' => $aggregate->signal_id, 'resolution' => '1m', 'bucket_start' => '2026-10-05 08:00:00']))
        ->toThrow(UniqueConstraintViolationException::class);

    ProcessAggregate::factory()->create(['signal_id' => $aggregate->signal_id, 'resolution' => '1h', 'bucket_start' => '2026-10-05 08:00:00']);
    expect(ProcessAggregate::query()->count())->toBe(2);
});

it('refuses a second dirty mark for one bucket of a signal', function (): void {
    $sample = ProcessSample::factory()->create();
    $row = ['company_id' => $sample->company_id, 'signal_id' => $sample->signal_id, 'bucket_start' => '2026-10-05 08:00:00', 'marked_at' => '2026-10-05 08:00:05'];
    DB::table('mes_process_dirty_buckets')->insert($row);

    expect(fn () => DB::table('mes_process_dirty_buckets')->insert($row))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second summary row for one operation and signal', function (): void {
    $summary = OperationProcessSummary::factory()->create();

    expect(fn () => OperationProcessSummary::factory()->create(['production_order_operation_id' => $summary->production_order_operation_id, 'signal_id' => $summary->signal_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('has the retention and store defaults', function (): void {
    expect(config('mes.machine.raw_retention_days'))->toBe(30)
        ->and(config('mes.machine.minute_aggregate_retention_days'))->toBe(90)
        ->and(config('mes.machine.process_store'))->toBe('database');
});
