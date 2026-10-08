<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Process;

use DateTimeInterface;

/**
 * Where process value samples live. The relational driver is the default ({@see RelationalProcessValueStore});
 * a time-series database can implement the same contract later, selected by `mes.machine.process_store`.
 */
interface ProcessValueStore
{
    /**
     * Stores samples; a sample already stored (same signal, same moment) is skipped.
     *
     * @param  list<ProcessSample>  $samples
     * @return int how many were new
     */
    public function write(array $samples): int;

    /**
     * The aggregates of a signal at a resolution (`1m` or `1h`) with `from <= bucket_start < to`, in time order.
     *
     * @return list<ProcessAggregateRow>
     */
    public function aggregates(int $signal_id, DateTimeInterface $from, DateTimeInterface $to, string $resolution): array;

    /**
     * Rebuilds the aggregates of the buckets that received samples since they were last built, oldest first.
     *
     * @param  int  $limit  how many marked minute buckets to process in this call
     * @return int how many minute buckets were rebuilt
     */
    public function rollup(int $limit = 500): int;

    /**
     * Whether some bucket still waits to be rebuilt.
     */
    public function hasPendingRollup(): bool;

    /**
     * Deletes raw samples older than the given moment.
     *
     * @return int how many were deleted
     */
    public function prune(DateTimeInterface $before): int;

    /**
     * Deletes one-minute aggregates older than the given moment; hour aggregates stay.
     *
     * @return int how many were deleted
     */
    public function pruneAggregates(DateTimeInterface $before): int;

    /**
     * What each signal did while an operation ran (samples of bad quality left out).
     *
     * @param  array<int, array{min: ?float, max: ?float}>  $ranges  acceptable range by signal id
     * @return list<ProcessStatistics>
     */
    public function operationStatistics(int $operation_id, array $ranges): array;
}
