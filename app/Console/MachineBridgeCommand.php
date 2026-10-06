<?php

declare(strict_types=1);

namespace Modules\MES\Console;

use Illuminate\Console\Command;
use Modules\MES\Machine\Mqtt\MachineBridge;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Override;

/**
 * Runs the MQTT bridge until it receives SIGTERM or SIGINT, finishing the message it holds. Run it
 * under supervisor or systemd.
 */
final class MachineBridgeCommand extends Command
{
    #[Override]
    protected $signature = 'mes:machine-bridge';

    #[Override]
    protected $description = 'Subscribe to the MQTT broker and feed the machine message inbox <fg=magenta>(✨ Modules\MES)</fg=magenta>';

    public function handle(MachineBridge $bridge): int
    {
        $settings = MqttConnectionSettings::fromConfig();

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $bridge->stop());
            pcntl_signal(SIGINT, static fn () => $bridge->stop());
        }

        $this->info("Machine bridge connecting to {$settings->host}:{$settings->port}.");

        $bridge->run($settings);

        $this->info('Machine bridge stopped.');

        return self::SUCCESS;
    }
}
