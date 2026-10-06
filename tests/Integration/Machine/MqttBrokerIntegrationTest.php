<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Modules\MES\Machine\Mqtt\MachineBridge;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Modules\MES\Machine\Mqtt\MqttIngest;
use Modules\MES\Machine\Mqtt\MqttMessageRouter;
use Modules\MES\Machine\Mqtt\PhpMqttSubscriber;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * Runs against a real broker, and only when `MES_MQTT_TEST_BROKER` (host:port) is set.
 */
beforeEach(function (): void {
    $broker = getenv('MES_MQTT_TEST_BROKER');

    if (! is_string($broker) || ! str_contains($broker, ':')) {
        $this->markTestSkipped('Set MES_MQTT_TEST_BROKER=host:port to run the broker integration tests.');
    }

    [$this->host, $port] = explode(':', $broker);
    $this->port = (int) $port;
    MesTestHelpers::makeCompany();
    Queue::fake();
});

function integrationEnvelope(string $id): string
{
    return json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => $id, 'source_seq' => 1, 'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'd1', 'type' => 'data', 'samples' => [['signal' => 's', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 1]]]],
    ], JSON_THROW_ON_ERROR);
}

function publishToBroker(string $host, int $port, string $topic, string $payload): void
{
    $client = new MqttClient($host, $port, 'laraplate-test-publisher-' . bin2hex(random_bytes(4)), MqttClient::MQTT_3_1_1);
    $client->connect(new ConnectionSettings(), true);
    $client->publish($topic, $payload, MqttClient::QOS_AT_LEAST_ONCE);
    $client->disconnect();
}

/**
 * Runs the bridge until `$expected` messages are stored (or ten seconds pass); `$while_running` is called
 * once, after the bridge has subscribed.
 */
function runBridgeUntilStored(string $host, int $port, string $client_id, int $expected, ?Closure $while_running = null): void
{
    $bridge = new MachineBridge(new PhpMqttSubscriber(), resolve(MqttMessageRouter::class), resolve(MqttIngest::class));
    $deadline = time() + 10;
    $calls = 0;

    $bridge->run(new MqttConnectionSettings($host, $port, null, null, false, $client_id, 'laraplate'), static function () use ($deadline, $expected, &$calls, &$while_running): bool {
        if (++$calls === 3 && $while_running instanceof Closure) {
            $while_running();
            $while_running = null;
        }

        return time() < $deadline && MachineMessage::query()->count() < $expected;
    });
}

it('stores a message published to a source topic while the bridge runs', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'it-gw', 'mqtt_topic' => null]);

    runBridgeUntilStored($this->host, $this->port, 'laraplate-test-bridge-' . bin2hex(random_bytes(4)), 1, fn () => publishToBroker($this->host, $this->port, 'laraplate/laraplate-machine/1/it-gw', integrationEnvelope('integration-live')));

    expect(MachineMessage::query()->where('message_id', 'integration-live')->count())->toBe(1);
});

it('receives a message published while no bridge was connected (needs a broker with persistent sessions)', function (): void {
    if (getenv('MES_MQTT_TEST_PERSISTENT_SESSION') !== '1') {
        $this->markTestSkipped('Set MES_MQTT_TEST_PERSISTENT_SESSION=1 with a broker that keeps sessions (Mosquitto, EMQX).');
    }

    MachineSource::factory()->mqtt()->create(['code' => 'it-gw', 'mqtt_topic' => null]);
    $client_id = 'laraplate-test-bridge-' . bin2hex(random_bytes(4));

    // The first run only registers the subscription; the message waits on the broker for the second.
    runBridgeUntilStored($this->host, $this->port, $client_id, 0);
    publishToBroker($this->host, $this->port, 'laraplate/laraplate-machine/1/it-gw', integrationEnvelope('integration-persistent'));
    runBridgeUntilStored($this->host, $this->port, $client_id, 1);

    expect(MachineMessage::query()->where('message_id', 'integration-persistent')->count())->toBe(1);
});
