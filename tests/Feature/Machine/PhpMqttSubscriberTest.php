<?php

declare(strict_types=1);

use Modules\MES\Machine\Mqtt\MqttConnectionLost;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Modules\MES\Machine\Mqtt\MqttMessage;
use Modules\MES\Machine\Mqtt\PhpMqttSubscriber;
use PhpMqtt\Client\MqttClient;

function brokerSettings(int $port = 1, ?string $password = 'p4ssw0rd-secret', bool $tls = false): MqttConnectionSettings
{
    return new MqttConnectionSettings('127.0.0.1', $port, 'bridge', $password, $tls, 'laraplate-test-bridge', 'laraplate');
}

it('builds the client connection settings from ours', function (): void {
    $settings = new PhpMqttSubscriber()->connectionSettings(brokerSettings(8883, 'p4ssw0rd-secret', true));

    expect($settings->getUsername())->toBe('bridge')
        ->and($settings->getPassword())->toBe('p4ssw0rd-secret')
        ->and($settings->shouldUseTls())->toBeTrue()
        ->and($settings->shouldReconnectAutomatically())->toBeFalse()
        ->and($settings->getKeepAliveInterval())->toBe(30);
});

it('leaves credentials out of the settings when there are none', function (): void {
    $settings = new PhpMqttSubscriber()->connectionSettings(new MqttConnectionSettings('127.0.0.1', 1883, null, null, false, 'c', 'laraplate'));

    expect($settings->getUsername())->toBeNull()
        ->and($settings->getPassword())->toBeNull()
        ->and($settings->shouldUseTls())->toBeFalse();
});

it('reports an unreachable broker as a lost connection that does not leak the password', function (): void {
    $subscriber = new PhpMqttSubscriber();

    try {
        $subscriber->connect(brokerSettings());
        $thrown = null;
    } catch (MqttConnectionLost $lost) {
        $thrown = $lost;
    }

    expect($thrown)->toBeInstanceOf(MqttConnectionLost::class)
        ->and($thrown?->getMessage())->not->toContain('p4ssw0rd-secret');
});

it('can be disconnected before and after a failed connection, any number of times', function (): void {
    $subscriber = new PhpMqttSubscriber();
    $subscriber->disconnect();

    try {
        $subscriber->connect(brokerSettings());
    } catch (MqttConnectionLost) {
        // expected: nothing listens on port 1
    }

    $subscriber->disconnect();
    $subscriber->disconnect();

    expect(true)->toBeTrue();
});

it('refuses to subscribe or loop before it is connected', function (): void {
    $subscriber = new PhpMqttSubscriber();

    expect(fn () => $subscriber->subscribe(['a/b']))->toThrow(MqttConnectionLost::class)
        ->and(fn () => $subscriber->loop(static fn () => null, static fn (): bool => false))->toThrow(MqttConnectionLost::class);
});

it('takes every delivered message from the client message hook, not from per-subscription callbacks', function (): void {
    // php-mqtt delivers to a subscription callback only once its SUBACK arrived, so messages the broker
    // sends right after CONNACK (those queued while the bridge was down) would be acknowledged and dropped.
    $client = Mockery::mock(MqttClient::class);
    $client->shouldReceive('connect')->once();
    $captured = null;
    $client->shouldReceive('registerMessageReceivedEventHandler')->once()->andReturnUsing(function (Closure $handler) use (&$captured, $client): MqttClient {
        $captured = $handler;

        return $client;
    });
    $client->shouldReceive('subscribe')->once()->withArgs(static fn (string $filter, mixed $callback, int $qos): bool => $filter === 'a/#' && $callback === null && $qos === 1);
    $client->shouldReceive('registerLoopEventHandler')->andReturnSelf();
    $client->shouldReceive('unregisterLoopEventHandler')->andReturnSelf();
    $client->shouldReceive('loop')->once()->andReturnUsing(static function () use (&$captured, $client): void {
        $captured($client, 'a/b', 'payload', 1, false);
    });
    $client->shouldReceive('isConnected')->andReturn(true);
    $client->shouldReceive('disconnect');
    $subscriber = new PhpMqttSubscriber(static fn (): MqttClient => $client);
    $received = [];

    $subscriber->connect(brokerSettings());
    $subscriber->subscribe(['a/#']);
    $subscriber->loop(static function (MqttMessage $message) use (&$received): void {
        $received[] = [$message->topic, $message->payload, $message->qos];
    }, static fn (): bool => true);

    expect($received)->toBe([['a/b', 'payload', 1]]);
});

it('reports any failure of the client loop, a socket warning included, as a lost connection', function (): void {
    $client = Mockery::mock(MqttClient::class);
    $client->shouldReceive('connect');
    $client->shouldReceive('registerMessageReceivedEventHandler')->andReturnSelf();
    $client->shouldReceive('registerLoopEventHandler')->andReturnSelf();
    $client->shouldReceive('unregisterLoopEventHandler')->andReturnSelf();
    $client->shouldReceive('loop')->andThrow(new ErrorException('fread(): SSL: Connection reset by peer'));
    $client->shouldReceive('isConnected')->andReturn(false);
    $subscriber = new PhpMqttSubscriber(static fn (): MqttClient => $client);
    $subscriber->connect(brokerSettings());

    expect(fn () => $subscriber->loop(static fn () => null, static fn (): bool => true))->toThrow(MqttConnectionLost::class);
});
