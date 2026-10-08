<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Modules\MES\Machine\Process\ProcessValueStore;
use Override;

/**
 * Deletes the process value samples and the one-minute aggregates older than their retention. Hour
 * aggregates and the per-operation summaries are kept.
 */
final class PruneProcessValuesCommand extends Command
{
    #[Override]
    protected $signature = 'mes:machine-prune-process-values';

    #[Override]
    protected $description = 'Prune old process value samples and minute aggregates <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(ProcessValueStore $store): int
    {
        $samples = $store->prune(now()->subDays(config()->integer('mes.machine.raw_retention_days')));
        $aggregates = $store->pruneAggregates(now()->subDays(config()->integer('mes.machine.minute_aggregate_retention_days')));

        $this->info("Pruned {$samples} samples and {$aggregates} minute aggregates.");

        return self::SUCCESS;
    }
}
