<?php

declare(strict_types=1);

use Modules\MES\Machine\Counts\CounterDelta;

it('computes the delta of a cumulative counter', function (): void {
    expect(CounterDelta::between(100.0, 130.0, 'cumulative', null))->toBe(30.0)
        ->and(CounterDelta::between(130.0, 130.0, 'cumulative', null))->toBe(0.0);
});

it('takes the first cumulative sample as a baseline and the first delta sample as received', function (): void {
    expect(CounterDelta::between(null, 500.0, 'cumulative', null))->toBe(0.0)
        ->and(CounterDelta::between(null, 7.0, 'delta', null))->toBe(7.0)
        ->and(CounterDelta::between(12.0, 7.0, 'delta', null))->toBe(7.0);
});

it('recognises a rollover and a reset, and never returns a negative delta', function (): void {
    expect(CounterDelta::between(995.0, 5.0, 'cumulative', 1000.0))->toBe(10.0)
        ->and(CounterDelta::between(500.0, 3.0, 'cumulative', null))->toBe(3.0)
        ->and(CounterDelta::between(100.0, 3.0, 'cumulative', 1000.0))->toBe(3.0)
        ->and(CounterDelta::between(10.0, -4.0, 'delta', null))->toBe(0.0);
});
