<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

/**
 * What the bridge hands each broker message to.
 */
interface MqttMessageHandler
{
    public function handle(MqttMessage $message): mixed;
}
