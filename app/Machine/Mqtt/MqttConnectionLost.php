<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use RuntimeException;

/**
 * The connection to the broker dropped or could not be made; the bridge reconnects with backoff.
 */
final class MqttConnectionLost extends RuntimeException {}
