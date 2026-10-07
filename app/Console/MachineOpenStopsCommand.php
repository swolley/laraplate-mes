<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\MES\Machine\States\MachineDowntimeDeriver;
use Modules\MES\Models\MachineStateInterval;
use Override;

/**
 * A stop that is still going on produces no new message, so nothing would open its downtime once it
 * outlasts the micro-stop threshold: this sweep does.
 */
final class MachineOpenStopsCommand extends Command
{
    #[Override]
    protected $signature = 'mes:machine-open-stops';

    #[Override]
    protected $description = 'Open the downtime of machine stops that outlasted the micro-stop threshold <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(MachineDowntimeDeriver $deriver): int
    {
        $synced = 0;

        MachineStateInterval::query()
            ->open()
            ->whereHas('device', static fn ($devices) => $devices->where('is_active', true)->whereHas('source', static fn ($sources) => $sources->where('is_active', true)))
            ->each(function (MachineStateInterval $interval) use ($deriver, &$synced): void {
                try {
                    if ($deriver->sync($interval) !== null) {
                        $synced++;
                    }
                } catch (UniqueConstraintViolationException) {
                    // The processing job opened the same downtime a moment earlier: nothing left to do.
                }
            });

        $this->info("Open stops with a downtime: {$synced}.");

        return self::SUCCESS;
    }
}
