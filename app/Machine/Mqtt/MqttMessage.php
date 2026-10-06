<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

/**
 * One message delivered by the broker: its topic and the raw payload bytes.
 */
final readonly class MqttMessage
{
    public function __construct(
        public string $topic,
        public string $payload,
        public int $qos = 1,
    ) {}
}
