<?php

declare(strict_types=1);

use Modules\MES\Enums\MESTables;
use Modules\MES\Machine\Mqtt\MachineMessageSubscriber;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;

it('ships the MQTT defaults', function (): void {
    expect(config()->string('mes.machine.mqtt.host'))->toBe('127.0.0.1')
        ->and(config()->integer('mes.machine.mqtt.port'))->toBe(1883)
        ->and(config('mes.machine.mqtt.username'))->toBeNull()
        ->and(config('mes.machine.mqtt.password'))->toBeNull()
        ->and(config()->boolean('mes.machine.mqtt.tls'))->toBeFalse()
        ->and(config()->string('mes.machine.mqtt.client_id'))->toBe('laraplate-mes-bridge')
        ->and(config()->string('mes.machine.mqtt.topic_prefix'))->toBe('laraplate');
});

it('builds the connection settings from the configuration', function (): void {
    config(['mes.machine.mqtt.tls' => true, 'mes.machine.mqtt.username' => 'bridge', 'mes.machine.mqtt.password' => 'secret', 'mes.machine.mqtt.port' => 8883]);

    $settings = MqttConnectionSettings::fromConfig();

    expect($settings->host)->toBe('127.0.0.1')
        ->and($settings->port)->toBe(8883)
        ->and($settings->tls)->toBeTrue()
        ->and($settings->username)->toBe('bridge')
        ->and($settings->password)->toBe('secret')
        ->and($settings->client_id)->toBe('laraplate-mes-bridge')
        ->and($settings->topic_prefix)->toBe('laraplate');
});

it('registers the alias table and the subscriber contract', function (): void {
    expect(MESTables::SparkplugAliases->value)->toBe('mes_sparkplug_aliases')
        ->and(interface_exists(MachineMessageSubscriber::class))->toBeTrue()
        ->and(class_exists(PhpMqtt\Client\MqttClient::class))->toBeTrue();
});
