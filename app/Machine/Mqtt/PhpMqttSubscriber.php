<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Closure;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * {@see MachineMessageSubscriber} over php-mqtt/client: MQTT 3.1.1, QoS 1, a persistent session
 * under a stable client id (so messages published while the bridge is down wait on the broker).
 * Reconnecting is the bridge's job, with backoff, so the client does not reconnect by itself.
 */
final class PhpMqttSubscriber implements MachineMessageSubscriber
{
    private const int KEEP_ALIVE_SECONDS = 30;

    private ?MqttClient $client = null;

    /**
     * @var list<string>
     */
    private array $topics = [];

    private ?Closure $sink = null;

    public function connectionSettings(MqttConnectionSettings $settings): ConnectionSettings
    {
        return new ConnectionSettings()
            ->setUsername($settings->username)
            ->setPassword($settings->password)
            ->setUseTls($settings->tls)
            ->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS)
            ->setConnectTimeout(10)
            ->setReconnectAutomatically(false);
    }

    public function connect(MqttConnectionSettings $settings): void
    {
        $this->disconnect();

        try {
            $client = new MqttClient($settings->host, $settings->port, $settings->client_id, MqttClient::MQTT_3_1_1);
            $client->connect($this->connectionSettings($settings), false);
        } catch (Throwable $throwable) {
            throw new MqttConnectionLost("Could not connect to the MQTT broker at {$settings->host}:{$settings->port}: " . $throwable::class, 0, $throwable);
        }

        $this->client = $client;
        $this->topics = [];
    }

    public function subscribe(array $topics): void
    {
        $client = $this->connected();

        try {
            foreach (array_diff($this->topics, $topics) as $gone) {
                $client->unsubscribe($gone);
            }

            foreach (array_diff($topics, $this->topics) as $added) {
                $client->subscribe($added, function (string $topic, string $message): void {
                    if ($this->sink instanceof Closure) {
                        ($this->sink)(new MqttMessage($topic, $message, MqttClient::QOS_AT_LEAST_ONCE));
                    }
                }, MqttClient::QOS_AT_LEAST_ONCE);
            }
        } catch (MqttClientException $exception) {
            throw new MqttConnectionLost('The MQTT subscription failed: ' . $exception::class, 0, $exception);
        }

        $this->topics = $topics;
    }

    public function loop(callable $on_message, callable $should_continue): void
    {
        $client = $this->connected();
        $this->sink = $on_message(...);
        $check = static function (MqttClient $client) use ($should_continue): void {
            if (! $should_continue()) {
                $client->interrupt();
            }
        };
        $client->registerLoopEventHandler($check);

        try {
            $client->loop(true);
        } catch (MqttClientException $exception) {
            throw new MqttConnectionLost('The MQTT connection failed: ' . $exception::class, 0, $exception);
        } finally {
            $client->unregisterLoopEventHandler($check);
            $this->sink = null;
        }
    }

    public function disconnect(): void
    {
        $client = $this->client;
        $this->client = null;
        $this->topics = [];

        if ($client instanceof MqttClient && $client->isConnected()) {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // The socket is gone already; there is nothing left to close.
            }
        }
    }

    private function connected(): MqttClient
    {
        if (! $this->client instanceof MqttClient) {
            throw new MqttConnectionLost('The MQTT client is not connected.');
        }

        return $this->client;
    }
}
