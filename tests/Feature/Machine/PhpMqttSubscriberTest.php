<?php

declare(strict_types=1);

use Modules\MES\Machine\Mqtt\MqttConnectionLost;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Modules\MES\Machine\Mqtt\PhpMqttSubscriber;

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
