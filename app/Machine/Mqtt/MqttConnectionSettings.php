<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

/**
 * How to reach the broker, from `mes.machine.mqtt`.
 */
final readonly class MqttConnectionSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public bool $tls,
        public string $client_id,
        public string $topic_prefix,
    ) {}

    public static function fromConfig(): self
    {
        $username = config('mes.machine.mqtt.username');
        $password = config('mes.machine.mqtt.password');

        return new self(
            host: config()->string('mes.machine.mqtt.host'),
            port: config()->integer('mes.machine.mqtt.port'),
            username: is_string($username) && $username !== '' ? $username : null,
            password: is_string($password) && $password !== '' ? $password : null,
            tls: config()->boolean('mes.machine.mqtt.tls'),
            client_id: config()->string('mes.machine.mqtt.client_id'),
            topic_prefix: config()->string('mes.machine.mqtt.topic_prefix'),
        );
    }
}
