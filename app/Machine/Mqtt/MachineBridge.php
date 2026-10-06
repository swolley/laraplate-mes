<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * The long-running subscriber behind `mes:machine-bridge`. It subscribes to the topics of the active
 * mqtt sources (and re-reads them every minute), hands every message to {@see MqttIngest}, writes a
 * heartbeat the watchdog watches, and reconnects with backoff when the broker connection drops.
 *
 * `stop()` makes it finish the message it holds and return.
 */
final class MachineBridge
{
    public const string HEARTBEAT_KEY = 'mes:machine:bridge-heartbeat';

    private const int HEARTBEAT_EVERY_SECONDS = 10;

    private const int RELOAD_EVERY_SECONDS = 60;

    private const int MAX_BACKOFF_SECONDS = 60;

    private const int HEALTHY_AFTER_SECONDS = 30;

    /**
     * How long a store that fails because the database is down is retried before the message is given
     * up: php-mqtt acknowledges a QoS 1 message before the handler runs, so the broker will not send it again.
     */
    private const array STORE_RETRY_SECONDS = [1, 2, 4, 8, 16, 30];

    private bool $stopped = false;

    private int $last_heartbeat = 0;

    private int $last_reload = 0;

    /**
     * @var list<string>
     */
    private array $topics = [];

    private bool $healthy = false;

    private int $connected_since = 0;

    private readonly Closure $sleep;

    private readonly ?Closure $after_message;

    /**
     * @param  (callable(int): void)|null  $sleep  waits that many seconds; defaults to a real sleep
     * @param  (callable(MqttMessage): void)|null  $after_message  called after each handled message
     */
    public function __construct(
        private readonly MachineMessageSubscriber $subscriber,
        private readonly MqttMessageRouter $router,
        private readonly MqttMessageHandler $handler,
        ?callable $sleep = null,
        ?callable $after_message = null,
    ) {
        $this->sleep = $sleep === null ? static function (int $seconds): void {
            sleep($seconds);
        } : $sleep(...);
        $this->after_message = $after_message === null ? null : $after_message(...);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * @param  (callable(): bool)|null  $should_continue  an extra reason to keep running, checked with every tick
     */
    public function run(MqttConnectionSettings $settings, ?callable $should_continue = null): void
    {
        $backoff = 1;

        while (! $this->stopped && ($should_continue === null || $should_continue())) {
            try {
                $this->subscriber->connect($settings);
                $this->topics = $this->router->subscriptions();
                $this->subscriber->subscribe($this->topics);
                $this->last_reload = now()->getTimestamp();
                $this->connected_since = $this->last_reload;
                $this->heartbeat(force: true);

                while (! $this->stopped && ($should_continue === null || $should_continue())) {
                    $this->subscriber->loop(
                        fn (MqttMessage $message) => $this->handle($message),
                        fn (): bool => $this->tick($should_continue),
                    );
                }
            } catch (MqttConnectionLost $lost) {
                Log::warning('Machine bridge lost the broker connection.', ['reason' => $lost->getMessage()]);
                $this->subscriber->disconnect();

                if ($this->healthy || now()->getTimestamp() - $this->connected_since >= self::HEALTHY_AFTER_SECONDS) {
                    $backoff = 1;
                }

                $this->healthy = false;

                if (! $this->stopped) {
                    ($this->sleep)($backoff);
                }

                $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
            }
        }

        $this->subscriber->disconnect();
    }

    private function handle(MqttMessage $message): void
    {
        $retries = self::STORE_RETRY_SECONDS;

        while (true) {
            try {
                $this->handler->handle($message);
                $this->healthy = true;

                break;
            } catch (Throwable $throwable) {
                $database_down = $throwable instanceof PDOException; // a Laravel QueryException is one

                if ($database_down && $retries !== [] && ! $this->stopped) {
                    ($this->sleep)((int) array_shift($retries));

                    continue;
                }

                // Never the payload, nor the exception message (a query error carries its bindings): only where it came from and what failed.
                Log::error('Machine bridge could not handle a message.', ['topic' => $message->topic, 'exception' => $throwable::class]);

                break;
            }
        }

        if ($this->after_message instanceof Closure) {
            ($this->after_message)($message);
        }
    }

    /**
     * Runs between messages: keeps the heartbeat, follows the sources, tells the loop whether to go on.
     *
     * @param  (callable(): bool)|null  $should_continue
     */
    private function tick(?callable $should_continue): bool
    {
        $this->heartbeat();

        if (now()->getTimestamp() - $this->last_reload >= self::RELOAD_EVERY_SECONDS) {
            $this->last_reload = now()->getTimestamp();
            $topics = $this->router->subscriptions();

            if ($topics !== $this->topics) {
                try {
                    $this->subscriber->subscribe($topics);
                    $this->topics = $topics;
                } catch (Throwable $throwable) {
                    // Hook failures are swallowed by the client: keep the old topics, try again at the next reload.
                    Log::warning('Machine bridge could not update its subscriptions.', ['exception' => $throwable::class]);
                }
            }
        }

        return ! $this->stopped && ($should_continue === null || $should_continue());
    }

    private function heartbeat(bool $force = false): void
    {
        $now = now()->getTimestamp();

        if ($force || $now - $this->last_heartbeat >= self::HEARTBEAT_EVERY_SECONDS) {
            $this->last_heartbeat = $now;
            Cache::put(self::HEARTBEAT_KEY, $now, 120);
        }
    }
}
