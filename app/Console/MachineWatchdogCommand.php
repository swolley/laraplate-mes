<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Modules\MES\Machine\MachineWatchdog;
use Override;

/**
 * Opens an incident for every device that stopped sending, and closes it when data returns.
 */
final class MachineWatchdogCommand extends Command
{
    #[Override]
    protected $signature = 'mes:machine-watchdog';

    #[Override]
    protected $description = 'Open an incident for every silent machine device <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(MachineWatchdog $watchdog): int
    {
        $opened = $watchdog->sweep();

        $this->info("Silent devices newly reported: {$opened}.");

        return self::SUCCESS;
    }
}
