<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Events\PartsCounted;
use Modules\MES\Jobs\ProcessMachineMessageJob;
use Modules\MES\Listeners\PartsCountRecorder;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSample;
use Modules\MES\Models\MachineCount;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

/**
 * @return array{device: MachineDevice, good: MachineSignal, scrap: MachineSignal, total: MachineSignal}
 */
function countRig(array $good_config = ['mode' => 'cumulative', 'rollover_max' => 1000]): array
{
    $device = MachineDevice::factory()->create(['work_center_id' => WorkCenter::factory()->create()->id]);

    return [
        'device' => $device,
        'good' => MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'good', 'role' => SignalRole::GoodCount->value, 'config' => $good_config]),
        'scrap' => MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'scrap', 'role' => SignalRole::ScrapCount->value, 'config' => ['mode' => 'delta']]),
        'total' => MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'total', 'role' => SignalRole::TotalCount->value, 'config' => ['mode' => 'cumulative']]),
    ];
}

/**
 * Feeds count samples ("signal=value@HH:MM:SS" or "signal=value@HH:MM:SS#operation_id") of 2026-10-05.
 *
 * @param  array<string, mixed>  $rig
 * @param  list<string>  $samples
 */
function feedCounts(array $rig, array $samples): void
{
    $resolved = [];

    foreach ($samples as $sample) {
        [$left, $rest] = explode('=', $sample);
        [$value, $time] = explode('@', $rest);
        $operation = null;

        if (str_contains($time, '#')) {
            [$time, $operation] = explode('#', $time);
        }

        $ts = CarbonImmutable::parse("2026-10-05 {$time}", config()->string('app.timezone'));
        $resolved[] = new ResolvedSample($rig['device'], $rig[$left], new NormalizedSample('d', $left, $ts, (float) $value), $operation === null ? null : (int) $operation);
    }

    $device = $rig['device'];
    resolve(PartsCountRecorder::class)->handle(new PartsCounted((int) $device->company_id, $device->id, (int) $device->work_center_id, $resolved));
}

/**
 * @return list<array{string, float, float, float}>
 */
function countRows(): array
{
    return MachineCount::query()->orderBy('ts')->orderBy('signal_id')->get()
        ->map(static fn (MachineCount $row): array => [$row->ts->format('H:i:s'), (float) $row->good, (float) $row->scrap, (float) $row->total])
        ->all();
}

it('writes the delta of each signal in the column of its role', function (): void {
    $rig = countRig();

    feedCounts($rig, ['good=100@08:00:00', 'good=130@08:01:00', 'scrap=2@08:01:00', 'total=500@08:00:00', 'total=535@08:01:00']);

    expect(countRows())->toBe([
        ['08:00:00', 0.0, 0.0, 0.0],
        ['08:00:00', 0.0, 0.0, 0.0],
        ['08:01:00', 30.0, 0.0, 0.0],
        ['08:01:00', 0.0, 2.0, 0.0],
        ['08:01:00', 0.0, 0.0, 35.0],
    ]);
});

it('handles a rollover and a reset without a negative delta', function (): void {
    $rig = countRig();

    feedCounts($rig, ['good=990@08:00:00', 'good=5@08:01:00', 'good=2@08:02:00']);

    $good = MachineCount::query()->where('signal_id', $rig['good']->id)->orderBy('ts')->pluck('good')->map(static fn ($v): float => (float) $v)->all();
    expect($good)->toBe([0.0, 15.0, 2.0]);
});

it('stores the same rows when the same samples arrive twice', function (): void {
    $rig = countRig();
    $operation = Modules\MES\Models\ProductionOrderOperation::factory()->create();
    $samples = ['good=100@08:00:00', 'good=130@08:01:00', "good=150@08:02:00#{$operation->id}"];

    feedCounts($rig, $samples);
    $once = MachineCount::query()->orderBy('id')->get()->toArray();
    feedCounts($rig, $samples);

    expect(MachineCount::query()->orderBy('id')->get()->toArray())->toEqual($once);
});

it('inserts a late sample by its time and recomputes the delta of the row after it', function (): void {
    $rig = countRig();
    feedCounts($rig, ['good=100@08:00:00', 'good=150@08:02:00']);

    feedCounts($rig, ['good=120@08:01:00']);

    $good = MachineCount::query()->where('signal_id', $rig['good']->id)->orderBy('ts')->pluck('good')->map(static fn ($v): float => (float) $v)->all();
    expect($good)->toBe([0.0, 20.0, 30.0]);
});

it('uses a delta counter as received and keeps the attribution', function (): void {
    $rig = countRig(['mode' => 'delta']);
    $operation = Modules\MES\Models\ProductionOrderOperation::factory()->create();

    feedCounts($rig, ["good=4@08:00:00#{$operation->id}", 'good=6@08:01:00']);

    $rows = MachineCount::query()->where('signal_id', $rig['good']->id)->orderBy('ts')->get();
    expect($rows->map(static fn ($r): float => (float) $r->good)->all())->toBe([4.0, 6.0])
        ->and($rows->first()->production_order_operation_id)->toBe($operation->id)
        ->and($rows->last()->production_order_operation_id)->toBeNull();
});

it('produces the rows through the whole pipeline and leaves them unchanged on reprocessing', function (): void {
    $rig = countRig();
    $payload = json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => 'counts-1', 'source_seq' => 1, 'sent_at' => '2026-10-05T06:00:00.000Z',
        'devices' => [['device' => $rig['device']->external_id, 'type' => 'data', 'samples' => [
            ['signal' => 'good', 'ts' => '2026-10-05T06:00:00.000Z', 'value' => 10],
            ['signal' => 'good', 'ts' => '2026-10-05T06:01:00.000Z', 'value' => 25],
            ['signal' => 'scrap', 'ts' => '2026-10-05T06:01:00.000Z', 'value' => 1],
        ]]],
    ], JSON_THROW_ON_ERROR);
    $message = MachineMessage::factory()->create(['source_id' => $rig['device']->source_id, 'message_id' => 'counts-1', 'payload' => $payload]);

    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id), 'handle']);
    $once = MachineCount::query()->orderBy('id')->get()->toArray();
    app()->call([new ProcessMachineMessageJob($message->id, $message->source_id, true), 'handle']);

    expect(MachineCount::query()->orderBy('id')->get()->toArray())->toEqual($once)
        ->and($message->fresh()->status)->toBe(MachineMessageStatus::Processed)
        ->and(MachineCount::query()->count())->toBe(3)
        ->and((float) MachineCount::query()->where('signal_id', $rig['good']->id)->sum('good'))->toBe(15.0);
});
