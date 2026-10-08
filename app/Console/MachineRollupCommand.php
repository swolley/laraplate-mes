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
    #[Override]
    protected $signature = 'mes:machine-rollup';

    #[Override]
    protected $description = 'Roll up the process value samples into minute and hour aggregates <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(ProcessValueStore $store): int
    {
        $rebuilt = $store->rollup();

        $this->info("Minute buckets rebuilt: {$rebuilt}.");

        return self::SUCCESS;
    }
}
