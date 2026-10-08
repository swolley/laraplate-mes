<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Process;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Machine\States\MachineTime;
use Modules\MES\Machine\Support\IdempotentWriter;
use Modules\MES\Models\ProcessAggregate;
use Modules\MES\Models\ProcessSample as ProcessSampleRow;

/**
 * The default process value store: raw samples and aggregates in the application database.
 *
 * Writing a sample marks its minute dirty; {@see self::rollup()} rebuilds the minute from the raw samples and
 * the hour from the minute aggregates (raw samples are pruned long before hours stop being useful). Buckets
 * follow the application timezone.
 */
final class RelationalProcessValueStore implements ProcessValueStore
{
    private const int CHUNK = 500;

    public function __construct(
        private readonly IdempotentWriter $writer,
    ) {}

    #[\Override]
    public function write(array $samples): int
    {
        if ($samples === []) {
            return 0;
        }

        $connection = (new ProcessSampleRow())->getConnection();
        $now = now()->format('Y-m-d H:i:s');
        $stored = 0;
        $marks = [];

        foreach (array_chunk($samples, self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $sample) {
                $rows[] = [
                    'company_id' => $sample->company_id,
                    'signal_id' => $sample->signal_id,
                    'device_id' => $sample->device_id,
                    'work_center_id' => $sample->work_center_id,
                    'production_order_operation_id' => $sample->production_order_operation_id,
                    'ts' => MachineTime::db($sample->ts),
                    'value' => $sample->value,
                    'quality' => $sample->quality->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $bucket = MachineTime::local($sample->ts)->startOfMinute()->format('Y-m-d H:i:s.v');
                $marks["{$sample->signal_id}|{$bucket}"] = ['company_id' => $sample->company_id, 'signal_id' => $sample->signal_id, 'bucket_start' => $bucket];
            }

            $stored += $this->writer->insertMany($connection, MESTables::ProcessSamples->value, $rows);
        }

        $this->mark(array_values($marks));

        return $stored;
    }

    #[\Override]
    public function aggregates(int $signal_id, DateTimeInterface $from, DateTimeInterface $to, string $resolution): array
    {
        $rows = ProcessAggregate::query()
            ->withoutGlobalScopes()
            ->toBase()
            ->where('signal_id', $signal_id)
            ->where('resolution', $resolution)
            ->where('bucket_start', '>=', MachineTime::db(Carbon::parse($from)))
            ->where('bucket_start', '<', MachineTime::db(Carbon::parse($to)))
            ->orderBy('bucket_start')
            ->get();

        return array_values($rows->map(fn (object $row): ProcessAggregateRow => new ProcessAggregateRow(
            (int) $row->signal_id,
            (string) $row->resolution,
            $this->moment($row->bucket_start),
            $this->number($row->min),
            $this->number($row->max),
            $this->number($row->avg),
            $this->number($row->last),
            (int) $this->number($row->count),
        ))->all());
    }

    #[\Override]
    public function rollup(int $limit = 500): int
    {
        $marks = DB::table(MESTables::ProcessDirtyBuckets->value)->orderBy('marked_at')->limit($limit)->get();
        $raw_cutoff = MachineTime::db(CarbonImmutable::now()->subDays(config()->integer('mes.machine.raw_retention_days'))->startOfMinute());
        $rebuilt = 0;
        $hours = [];

        foreach ($marks as $mark) {
            $bucket = (string) $mark->bucket_start;

            if ($bucket >= $raw_cutoff && $this->rebuildMinute((int) $mark->company_id, (int) $mark->signal_id, $bucket)) {
                $rebuilt++;
            }

            $hour = $this->moment($bucket)->startOfHour()->format('Y-m-d H:i:s.v');
            $hours["{$mark->signal_id}|{$hour}"] = [(int) $mark->company_id, (int) $mark->signal_id, $hour];
        }

        foreach ($hours as [$company_id, $signal_id, $hour]) {
            $this->rebuildHour($company_id, $signal_id, $hour);
        }

        foreach ($marks as $mark) {
            // A mark set again while this ran has a newer time and stays for the next run.
            DB::table(MESTables::ProcessDirtyBuckets->value)
                ->where('signal_id', $mark->signal_id)
                ->where('bucket_start', $mark->bucket_start)
                ->where('marked_at', $mark->marked_at)
                ->delete();
        }

        return $rebuilt;
    }

    #[\Override]
    public function prune(DateTimeInterface $before): int
    {
        return $this->samples()->where('ts', '<', MachineTime::db(Carbon::parse($before)))->delete();
    }

    #[\Override]
    public function pruneAggregates(DateTimeInterface $before): int
    {
        return $this->aggregateRows()->where('resolution', '1m')->where('bucket_start', '<', MachineTime::db(Carbon::parse($before)))->delete();
    }

    #[\Override]
    public function operationStatistics(int $operation_id, array $ranges): array
    {
        $rows = $this->samples()
            ->where('production_order_operation_id', $operation_id)
            ->where('quality', '!=', SampleQuality::Bad->value)
            ->groupBy('signal_id')
            ->selectRaw('signal_id, MIN(value) as min_value, MAX(value) as max_value, AVG(value) as avg_value, COUNT(*) as samples, MIN(ts) as first_ts, MAX(ts) as last_ts')
            ->orderBy('signal_id')
            ->get();

        $statistics = [];

        foreach ($rows as $row) {
            $range = $ranges[(int) $row->signal_id] ?? null;
            $statistics[] = new ProcessStatistics(
                (int) $row->signal_id,
                $this->number($row->min_value),
                $this->number($row->max_value),
                $this->number($row->avg_value),
                (int) $this->number($row->samples),
                $range === null ? 0 : $this->outOfRange($operation_id, (int) $row->signal_id, $range),
                $this->moment($row->first_ts),
                $this->moment($row->last_ts),
            );
        }

        return $statistics;
    }

    /**
     * @param  array{min: ?float, max: ?float}  $range
     */
    private function outOfRange(int $operation_id, int $signal_id, array $range): int
    {
        if ($range['min'] === null && $range['max'] === null) {
            return 0;
        }

        return $this->samples()
            ->where('production_order_operation_id', $operation_id)
            ->where('signal_id', $signal_id)
            ->where('quality', '!=', SampleQuality::Bad->value)
            ->where(static function (Builder $query) use ($range): void {
                if ($range['min'] !== null) {
                    $query->where('value', '<', $range['min']);
                }

                if ($range['max'] !== null) {
                    $query->orWhere('value', '>', $range['max']);
                }
            })
            ->count();
    }

    /**
     * Rebuilds one minute from its raw samples.
     *
     * @return bool whether the minute was rebuilt (false: its raw samples are gone, the aggregate stays)
     */
    private function rebuildMinute(int $company_id, int $signal_id, string $bucket): bool
    {
        $end = $this->moment($bucket)->addMinute()->format('Y-m-d H:i:s.v');
        $minute = fn (): Builder => $this->samples()->where('signal_id', $signal_id)->where('ts', '>=', $bucket)->where('ts', '<', $end);

        if (! $minute()->exists()) {
            return false;
        }

        $stats = $minute()->where('quality', '!=', SampleQuality::Bad->value)->selectRaw('MIN(value) as min_value, MAX(value) as max_value, AVG(value) as avg_value, COUNT(*) as samples')->first();

        if ($stats === null || (int) $this->number($stats->samples) === 0) {
            $this->aggregateRows()->where('signal_id', $signal_id)->where('resolution', '1m')->where('bucket_start', $bucket)->delete();

            return true;
        }

        $last = $minute()->where('quality', '!=', SampleQuality::Bad->value)->orderByDesc('ts')->value('value');

        $this->upsertAggregate($company_id, $signal_id, '1m', $bucket, $this->number($stats->min_value), $this->number($stats->max_value), $this->number($stats->avg_value), $this->number($last), (int) $this->number($stats->samples));

        return true;
    }

    /**
     * Rebuilds one hour from the one-minute aggregates inside it.
     */
    private function rebuildHour(int $company_id, int $signal_id, string $hour): void
    {
        $end = $this->moment($hour)->addHour()->format('Y-m-d H:i:s.v');
        $minutes = fn (): Builder => $this->aggregateRows()->where('signal_id', $signal_id)->where('resolution', '1m')->where('bucket_start', '>=', $hour)->where('bucket_start', '<', $end);

        $stats = $minutes()->selectRaw('MIN(min) as min_value, MAX(max) as max_value, SUM(count) as samples, SUM(avg * count) as weighted')->first();
        $samples = $stats === null ? 0 : (int) $this->number($stats->samples);

        if ($stats === null || $samples === 0) {
            $this->aggregateRows()->where('signal_id', $signal_id)->where('resolution', '1h')->where('bucket_start', $hour)->delete();

            return;
        }

        $last = $minutes()->orderByDesc('bucket_start')->value('last');

        $this->upsertAggregate($company_id, $signal_id, '1h', $hour, $this->number($stats->min_value), $this->number($stats->max_value), $this->number($stats->weighted) / $samples, $this->number($last), $samples);
    }

    private function upsertAggregate(int $company_id, int $signal_id, string $resolution, string $bucket, float $min, float $max, float $avg, float $last, int $count): void
    {
        $now = now()->format('Y-m-d H:i:s');

        DB::table(MESTables::ProcessAggregates->value)->upsert(
            [['company_id' => $company_id, 'signal_id' => $signal_id, 'resolution' => $resolution, 'bucket_start' => $bucket, 'min' => $min, 'max' => $max, 'avg' => $avg, 'last' => $last, 'count' => $count, 'created_at' => $now, 'updated_at' => $now]],
            ['signal_id', 'resolution', 'bucket_start'],
            ['min', 'max', 'avg', 'last', 'count', 'updated_at'],
        );
    }

    /**
     * @param  list<array{company_id: int, signal_id: int, bucket_start: string}>  $marks
     */
    private function mark(array $marks): void
    {
        $at = now()->format('Y-m-d H:i:s.v');

        foreach (array_chunk($marks, self::CHUNK) as $chunk) {
            DB::table(MESTables::ProcessDirtyBuckets->value)->upsert(
                array_map(static fn (array $mark): array => $mark + ['marked_at' => $at], $chunk),
                ['signal_id', 'bucket_start'],
                ['marked_at'],
            );
        }
    }

    private function samples(): Builder
    {
        return ProcessSampleRow::query()->withoutGlobalScopes()->toBase();
    }

    private function aggregateRows(): Builder
    {
        return ProcessAggregate::query()->withoutGlobalScopes()->toBase();
    }

    private function moment(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse(is_string($value) ? $value : '1970-01-01 00:00:00', config()->string('app.timezone'));
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
