<?php

declare(strict_types=1);

use Modules\MES\Enums\MESTables;

it('ships the machine connectivity defaults', function (): void {
    expect(config()->string('mes.machine.queue'))->toBe('mes-machine')
        ->and(config()->integer('mes.machine.max_samples'))->toBe(5000)
        ->and(config()->integer('mes.machine.max_body_kb'))->toBe(1024)
        ->and(config()->integer('mes.machine.clock_skew_seconds'))->toBe(30)
        ->and(config()->integer('mes.machine.inbox_retention_days'))->toBe(7)
        ->and(config()->integer('mes.machine.rate_limit_per_minute'))->toBe(600);
});

it('registers the machine tables', function (): void {
    expect(MESTables::MachineSources->value)->toBe('mes_machine_sources')
        ->and(MESTables::MachineProfiles->value)->toBe('mes_machine_profiles')
        ->and(MESTables::MachineDevices->value)->toBe('mes_machine_devices')
        ->and(MESTables::MachineSignals->value)->toBe('mes_machine_signals')
        ->and(MESTables::UnmappedSignals->value)->toBe('mes_unmapped_signals')
        ->and(MESTables::MachineMessages->value)->toBe('mes_machine_messages')
        ->and(MESTables::MachineIncidents->value)->toBe('mes_machine_incidents');
});
