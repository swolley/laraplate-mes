<?php

declare(strict_types=1);

namespace Modules\MES\Tests\Support;

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
     * @var list<MqttConnectionLost>
     */
    private array $failures = [];

    public function push(MqttMessage $message): void
    {
        $this->queue[] = $message;
    }

    public function failNextLoopWith(MqttConnectionLost $failure): void
    {
        $this->failures[] = $failure;
    }

    public function connect(MqttConnectionSettings $settings): void
    {
        $this->connects++;
    }

    public function subscribe(array $topics): void
    {
        $this->subscriptions[] = $topics;
    }

    public function loop(callable $on_message, callable $should_continue): void
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
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
