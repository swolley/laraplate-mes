<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Modules\MES\Machine\Process\ProcessValueStore;
use Override;

/**
 * Rebuilds the process value aggregates of the minutes (and hours) that received samples since the last run.
 */
final class MachineRollupCommand extends Command
{
    private const int BUDGET_SECONDS = 50;

    #[Override]
    protected $signature = 'mes:machine-rollup';

    #[Override]
    protected $description = 'Roll up the process value samples into minute and hour aggregates <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(ProcessValueStore $store): int
    {
        // Batches until nothing waits, within a time budget so one run never outlives its minute.
        $deadline = now()->addSeconds(self::BUDGET_SECONDS);
        $rebuilt = 0;

        do {
            $rebuilt += $store->rollup();
        } while ($store->hasPendingRollup() && now()->lessThan($deadline));

        $this->info("Minute buckets rebuilt: {$rebuilt}.");

        return self::SUCCESS;
    }
}
