<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    private bool $stopped = false;

    private int $last_heartbeat = 0;

    private int $last_reload = 0;

    /**
     * @var list<string>
     */
    private array $topics = [];

    private bool $healthy = false;

    private readonly Closure $sleep;

    private readonly ?Closure $after_message;

    /**
     * @param  (callable(int): void)|null  $sleep  waits that many seconds; defaults to a real sleep
     * @param  (callable(MqttMessage): void)|null  $after_message  called after each handled message
     */
    public function __construct(
        private readonly MachineMessageSubscriber $subscriber,
        private readonly MqttMessageRouter $router,
        private readonly MqttIngest $ingest,
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

                if ($this->healthy) {
                    $backoff = 1;
                    $this->healthy = false;
                }

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
        try {
            $this->ingest->handle($message);
            $this->healthy = true;
        } catch (Throwable $throwable) {
            // Never the payload: only where it came from and what went wrong.
            Log::error('Machine bridge could not handle a message.', ['topic' => $message->topic, 'exception' => $throwable::class, 'reason' => $throwable->getMessage()]);
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
                $this->topics = $topics;
                $this->subscriber->subscribe($topics);
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
