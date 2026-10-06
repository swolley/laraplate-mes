<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\MES\Machine\Mqtt\MachineBridge;
use Modules\MES\Machine\Mqtt\MqttConnectionLost;
use Modules\MES\Machine\Mqtt\MqttConnectionSettings;
use Modules\MES\Machine\Mqtt\MqttIngest;
use Modules\MES\Machine\Mqtt\MqttMessage;
use Modules\MES\Machine\Mqtt\MqttMessageRouter;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\FakeMachineMessageSubscriber;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
    Queue::fake();
    Cache::flush();
    Carbon::setTestNow('2026-10-07 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function bridgeEnvelope(string $id): string
{
    return json_encode([
        'protocol' => 'laraplate-machine/1', 'message_id' => $id, 'source_seq' => 1, 'sent_at' => now()->toIso8601ZuluString('millisecond'),
        'devices' => [['device' => 'd1', 'type' => 'data', 'samples' => [['signal' => 's', 'ts' => now()->toIso8601ZuluString('millisecond'), 'value' => 1]]]],
    ], JSON_THROW_ON_ERROR);
}

function makeBridge(FakeMachineMessageSubscriber $subscriber, ?callable $sleep = null, ?callable $after = null): MachineBridge
{
    return new MachineBridge($subscriber, resolve(MqttMessageRouter::class), resolve(MqttIngest::class), $sleep, $after);
}

/**
 * A `$should_continue` that allows this many checks.
 */
function checks(int $allowed, ?callable $each = null): Closure
{
    $count = 0;

    return static function () use (&$count, $allowed, $each): bool {
        $count++;

        if ($each !== null) {
            $each($count);
        }

        return $count <= $allowed;
    };
}

it('delivers the messages of the broker to the inbox', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $subscriber = new FakeMachineMessageSubscriber();
    $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('a')));
    $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('b')));

    makeBridge($subscriber)->run(MqttConnectionSettings::fromConfig(), checks(5));

    expect(MachineMessage::query()->count())->toBe(2)
        ->and($subscriber->connects)->toBe(1)
        ->and($subscriber->subscriptions[0])->toBe(['laraplate/laraplate-machine/1/gw-1'])
        ->and($subscriber->disconnects)->toBeGreaterThanOrEqual(1);
});

it('writes a heartbeat that moves forward', function (): void {
    $subscriber = new FakeMachineMessageSubscriber();

    makeBridge($subscriber)->run(MqttConnectionSettings::fromConfig(), checks(3, static function (int $count): void {
        if ($count === 2) {
            Carbon::setTestNow(now()->addSeconds(15));
        }
    }));

    expect(Cache::get(MachineBridge::HEARTBEAT_KEY))->toBe(now()->getTimestamp());
});

it('picks up a new source without a restart', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'a', 'mqtt_topic' => null]);
    $subscriber = new FakeMachineMessageSubscriber();

    makeBridge($subscriber)->run(MqttConnectionSettings::fromConfig(), checks(4, static function (int $count): void {
        if ($count === 2) {
            MachineSource::factory()->mqtt()->create(['code' => 'b', 'mqtt_topic' => null]);
            Carbon::setTestNow(now()->addSeconds(61));
        }
    }));

    expect($subscriber->subscriptions)->toHaveCount(2)
        ->and($subscriber->subscriptions[1])->toEqualCanonicalizing(['laraplate/laraplate-machine/1/a', 'laraplate/laraplate-machine/1/b']);
});

it('reconnects and resubscribes after a lost connection, backing off 1, 2, then starting over after a message', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $subscriber = new FakeMachineMessageSubscriber();
    $subscriber->failNextLoopWith(new MqttConnectionLost('down'));
    $subscriber->failNextLoopWith(new MqttConnectionLost('still down'));
    $sleeps = [];

    makeBridge($subscriber, static function (int $seconds) use (&$sleeps, $subscriber): void {
        $sleeps[] = $seconds;

        if (count($sleeps) === 2) {
            $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('after')));
            $subscriber->failNextLoopWith(new MqttConnectionLost('again'));
        }
    })->run(MqttConnectionSettings::fromConfig(), checks(10));

    expect($subscriber->connects)->toBeGreaterThanOrEqual(3)
        ->and($subscriber->subscriptions)->toHaveCount($subscriber->connects)
        ->and(array_slice($sleeps, 0, 2))->toBe([1, 2]);
});

it('does not stop for a message it cannot handle', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $subscriber = new FakeMachineMessageSubscriber();
    $subscriber->push(new MqttMessage('nobody/listens', 'x'));
    $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('ok')));
    Log::spy();

    makeBridge($subscriber)->run(MqttConnectionSettings::fromConfig(), checks(5));

    expect(MachineMessage::query()->count())->toBe(1);
});

it('finishes the message it holds when asked to stop, and handles no later one', function (): void {
    MachineSource::factory()->mqtt()->create(['code' => 'gw-1', 'mqtt_topic' => null]);
    $subscriber = new FakeMachineMessageSubscriber();
    $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('first')));
    $subscriber->push(new MqttMessage('laraplate/laraplate-machine/1/gw-1', bridgeEnvelope('second')));
    $bridge = null;
    $bridge = makeBridge($subscriber, null, static function () use (&$bridge): void {
        $bridge->stop();
    });

    $bridge->run(MqttConnectionSettings::fromConfig());

    expect(MachineMessage::query()->pluck('message_id')->all())->toBe(['first'])
        ->and($subscriber->disconnects)->toBeGreaterThanOrEqual(1);
});

it('registers the bridge command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('mes:machine-bridge');
});
