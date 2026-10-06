<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

/**
 * The MQTT client as the bridge needs it. The real one wraps php-mqtt; tests use a fake.
 */
interface MachineMessageSubscriber
{
    /**
     * @throws MqttConnectionLost
     */
    public function connect(MqttConnectionSettings $settings): void;

    /**
     * Subscribes at QoS 1 to exactly these topic filters, dropping any previous ones.
     *
     * @param  list<string>  $topics
     *
     * @throws MqttConnectionLost
     */
    public function subscribe(array $topics): void;

    /**
     * Blocks, calling `$on_message` for each message, until `$should_continue` returns false.
     *
     * @param  callable(MqttMessage): void  $on_message
     * @param  callable(): bool  $should_continue
     *
     * @throws MqttConnectionLost
     */
    public function loop(callable $on_message, callable $should_continue): void;

    public function disconnect(): void;
}
