<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Mqtt;

use Closure;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * {@see MachineMessageSubscriber} over php-mqtt/client: MQTT 3.1.1, QoS 1, a persistent session
 * under a stable client id (so messages published while the bridge is down wait on the broker).
 * Reconnecting is the bridge's job, with backoff, so the client does not reconnect by itself.
 *
 * Messages are taken from the client's message-received hook, not from per-subscription callbacks:
 * the library only activates a subscription when its SUBACK arrives, and the broker sends the messages
 * queued for a persistent session right after CONNACK, before it answers the subscription. A callback
 * would miss them, after the library had already acknowledged them.
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

    private readonly ?Closure $client_factory;

    /**
     * @param  (callable(MqttConnectionSettings): MqttClient)|null  $client_factory  builds the client; a test supplies its own
     */
    public function __construct(?callable $client_factory = null)
    {
        $this->client_factory = $client_factory === null ? null : $client_factory(...);
    }

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
            $client = $this->client_factory instanceof Closure
                ? ($this->client_factory)($settings)
                : new MqttClient($settings->host, $settings->port, $settings->client_id, MqttClient::MQTT_3_1_1);
            $client->registerMessageReceivedEventHandler(function (MqttClient $client, string $topic, string $message, int $qos): void {
                if ($this->sink instanceof Closure) {
                    ($this->sink)(new MqttMessage($topic, $message, $qos));
                }
            });
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
                $client->subscribe($added, null, MqttClient::QOS_AT_LEAST_ONCE);
            }
        } catch (Throwable $throwable) {
            throw new MqttConnectionLost('The MQTT subscription failed: ' . $throwable::class, 0, $throwable);
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
        } catch (Throwable $throwable) {
            // A dropped socket also surfaces as a PHP warning turned into an exception: all of it is a lost connection.
            throw new MqttConnectionLost('The MQTT connection failed: ' . $throwable::class, 0, $throwable);
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
