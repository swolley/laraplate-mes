<?php

declare(strict_types=1);

namespace Modules\MES\Tests\Support;

use Illuminate\Support\Carbon;
use Modules\MES\Machine\Mqtt\MachineMessageSubscriber;
use Modules\MES\Machine\Mqtt\MqttConnectionLost;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Modules\MES\Machine\Mqtt\MqttMessage;

/**
 * An in-memory subscriber: messages are pushed by the test and delivered by `loop()`.
 */
final class FakeMachineMessageSubscriber implements MachineMessageSubscriber
{
    public int $connects = 0;

    public int $disconnects = 0;

    /**
     * @var list<list<string>>
     */
    public array $subscriptions = [];

    /**
     * @var list<MqttMessage>
     */
    private array $queue = [];

    /**
     * @var list<array{MqttConnectionLost, int}>
     */
    private array $failures = [];

    private int $subscribe_failures = 0;

    public function push(MqttMessage $message): void
    {
        $this->queue[] = $message;
    }

    /**
     * The next `loop()` throws this after `$connected_for` seconds of (test) time.
     */
    public function failNextLoopWith(MqttConnectionLost $failure, int $connected_for = 0): void
    {
        $this->failures[] = [$failure, $connected_for];
    }

    /**
     * The next `$times` calls of `subscribe()` throw.
     */
    public function failSubscribeTimes(int $times): void
    {
        $this->subscribe_failures = $times;
    }

    public function connect(MqttConnectionSettings $settings): void
    {
        $this->connects++;
    }

    public function subscribe(array $topics): void
    {
        if ($this->subscribe_failures > 0) {
            $this->subscribe_failures--;

            throw new MqttConnectionLost('subscribe refused');
        }

        $this->subscriptions[] = $topics;
    }

    public function loop(callable $on_message, callable $should_continue): void
    {
        if ($this->failures !== []) {
            [$failure, $connected_for] = array_shift($this->failures);
            Carbon::setTestNow(now()->addSeconds($connected_for));

            throw $failure;
        }

        while ($should_continue()) {
            $message = array_shift($this->queue);

            if ($message instanceof MqttMessage) {
                $on_message($message);

                continue;
            }

            return;
        }
    }

    public function disconnect(): void
    {
        $this->disconnects++;
    }
}
