<?php

declare(strict_types=1);

namespace Modules\MES\Tests\Support;

use Modules\MES\Machine\Mqtt\MqttMessage;
use Modules\MES\Machine\Mqtt\MqttMessageHandler;
use PDOException;
use RuntimeException;

/**
 * A message handler that fails a number of times before it succeeds, recording what it was given.
 */
final class FlakyMessageHandler implements MqttMessageHandler
{
    /**
     * @var list<string>
     */
    public array $handled = [];

    public int $attempts = 0;

    public function __construct(private int $failures = 0, private readonly bool $database = true) {}

    public function handle(MqttMessage $message): mixed
    {
        $this->attempts++;

        if ($this->failures > 0) {
            $this->failures--;

            throw $this->database ? new PDOException('database is down') : new RuntimeException('a bug');
        }

        $this->handled[] = $message->topic;

        return null;
    }
}
