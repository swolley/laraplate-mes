<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\MES\Machine\SignalResolver;
use Modules\MES\Machine\UnmappedSignalRecorder;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Cache::flush();
});

it('resolves a configured signal to its device, work center and company', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'good_count']);
    $device = $signal->device;

    $resolved = resolve(SignalResolver::class)->resolve($device->source, $device->external_id, 'good_count');

    expect($resolved?->signal->id)->toBe($signal->id)
        ->and($resolved?->device->id)->toBe($device->id)
        ->and($resolved?->work_center_id)->toBe($device->work_center_id)
        ->and($resolved?->company_id)->toBe($device->company_id);
});

it('resolves nothing for an unknown device, an unknown signal or an inactive device', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'k']);
    $device = $signal->device;
    $inactive = MachineDevice::factory()->create(['source_id' => $device->source_id, 'external_id' => 'off', 'is_active' => false]);
    MachineSignal::factory()->create(['device_id' => $inactive->id, 'key' => 'k']);
    $resolver = resolve(SignalResolver::class);

    expect($resolver->resolve($device->source, 'ghost', 'k'))->toBeNull()
        ->and($resolver->resolve($device->source, $device->external_id, 'ghost'))->toBeNull()
        ->and($resolver->resolve($device->source, 'off', 'k'))->toBeNull();
});

it('reads the map once, and not again from another instance', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'k']);
    $source = $signal->device->source;
    resolve(SignalResolver::class)->resolve($source, $signal->device->external_id, 'k');

    DB::enableQueryLog();
    resolve(SignalResolver::class)->resolve($source, $signal->device->external_id, 'k');
    $resolver = new SignalResolver();
    $resolver->resolve($source, $signal->device->external_id, 'k');
    $resolver->resolve($source, $signal->device->external_id, 'k');

    expect(DB::getQueryLog())->toBe([]);
});

it('forgets the map when a signal or a device changes', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'k']);
    $device = $signal->device;
    $source = $device->source;
    expect(resolve(SignalResolver::class)->resolve($source, $device->external_id, 'late'))->toBeNull();

    MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'late']);
    expect(resolve(SignalResolver::class)->resolve($source, $device->external_id, 'late'))->not->toBeNull();

    $device->update(['is_active' => false]);
    expect(resolve(SignalResolver::class)->resolve($source, $device->external_id, 'late'))->toBeNull();
});

it('records an unmapped signal, counting it once per message', function (): void {
    $source = MachineSource::factory()->create();
    $recorder = resolve(UnmappedSignalRecorder::class);
    $ts = now()->subMinute()->toImmutable();

    $recorder->record($source, 'press-07', 'temp', '21.5', $ts, true);
    expect(UnmappedSignal::query()->sole()->seen_count)->toBe(1);

    $recorder->record($source, 'press-07', 'temp', '22.5', $ts->addSecond(), true);
    $row = UnmappedSignal::query()->sole();
    expect($row->seen_count)->toBe(2)
        ->and($row->last_value)->toBe('22.5');

    $recorder->record($source, 'press-07', 'temp', '23.5', $ts->addSeconds(2), false);
    expect(UnmappedSignal::query()->sole()->seen_count)->toBe(2);
});

it('never moves the last value backwards in time', function (): void {
    $source = MachineSource::factory()->create();
    $recorder = resolve(UnmappedSignalRecorder::class);
    $now = now()->toImmutable();

    $recorder->record($source, 'd', 'k', 'new', $now, true);
    $recorder->record($source, 'd', 'k', 'old', $now->subHour(), true);

    $row = UnmappedSignal::query()->sole();
    expect($row->last_value)->toBe('new')
        ->and($row->seen_count)->toBe(2);
});

it('records many unmapped signals in a bounded number of queries', function (): void {
    $source = MachineSource::factory()->create();
    $entries = array_map(static fn (int $i): array => ['device' => 'd' . ($i % 5), 'key' => "k{$i}", 'value' => (string) $i, 'ts' => now()->toImmutable()], range(1, 100));

    DB::enableQueryLog();
    resolve(UnmappedSignalRecorder::class)->recordMany($source, $entries, true);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2)
        ->and(UnmappedSignal::query()->count())->toBe(100);
});

it('keeps the sample time as the same instant, and never moves the last value backwards', function (): void {
    $source = MachineSource::factory()->create();
    $recorder = resolve(UnmappedSignalRecorder::class);
    $newer = Carbon\CarbonImmutable::parse('2026-07-01 10:00:00', 'UTC');

    $recorder->record($source, 'd', 'k', 'new', $newer, true);
    expect(UnmappedSignal::query()->sole()->last_seen_at->getTimestamp())->toBe($newer->getTimestamp());

    $recorder->record($source, 'd', 'k', 'old', $newer->subMinutes(30), true);

    $row = UnmappedSignal::query()->sole();
    expect($row->last_value)->toBe('new')
        ->and($row->last_seen_at->getTimestamp())->toBe($newer->getTimestamp());
});

it('forgets the map after the surrounding transaction commits, not before', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'k']);
    $device = $signal->device;
    $source = $device->source;
    resolve(SignalResolver::class)->resolve($source, $device->external_id, 'k');

    DB::transaction(function () use ($device, $source): void {
        MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'late']);
        // A job running before the commit caches the map it can see.
        new SignalResolver()->resolve($source, $device->external_id, 'late');
    });

    expect(resolve(SignalResolver::class)->resolve($source, $device->external_id, 'late'))->not->toBeNull();
});

it('forgets the old source map when a device moves to another source', function (): void {
    $signal = MachineSignal::factory()->create(['key' => 'k']);
    $device = $signal->device;
    $old_source = $device->source;
    $new_source = MachineSource::factory()->create();
    expect(resolve(SignalResolver::class)->resolve($old_source, $device->external_id, 'k'))->not->toBeNull();

    $device->update(['source_id' => $new_source->id]);

    expect(resolve(SignalResolver::class)->resolve($old_source, $device->external_id, 'k'))->toBeNull();
});
