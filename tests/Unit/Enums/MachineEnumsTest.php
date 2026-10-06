<?php

declare(strict_types=1);

use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Enums\MachineState;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Enums\SampleQuality;
use Modules\MES\Enums\SignalRole;

it('defines the machine value sets', function (string $enum, array $values): void {
    expect($enum::values())->toBe($values)
        ->and($enum::validationRule())->toBe('in:' . implode(',', $values));
})->with([
    'signal role' => [SignalRole::class, ['state', 'alarm', 'good_count', 'scrap_count', 'total_count', 'measurement', 'process_value', 'order_reference', 'operation_reference']],
    'machine state' => [MachineState::class, ['running', 'idle', 'setup', 'stopped', 'fault', 'maintenance', 'offline']],
    'transport' => [MachineTransport::class, ['http', 'mqtt']],
    'message status' => [MachineMessageStatus::class, ['pending', 'processed', 'failed']],
    'sample quality' => [SampleQuality::class, ['good', 'uncertain', 'bad']],
    'incident type' => [MachineIncidentType::class, ['seq_gap', 'clock_skew', 'message_failed', 'auth_failure', 'bridge_down', 'device_silent']],
]);

it('classifies signal roles', function (): void {
    expect(SignalRole::GoodCount->isCount())->toBeTrue()
        ->and(SignalRole::ScrapCount->isCount())->toBeTrue()
        ->and(SignalRole::TotalCount->isCount())->toBeTrue()
        ->and(SignalRole::State->isCount())->toBeFalse()
        ->and(SignalRole::OrderReference->isReference())->toBeTrue()
        ->and(SignalRole::OperationReference->isReference())->toBeTrue()
        ->and(SignalRole::Measurement->isReference())->toBeFalse();
});

it('reads a machine state from its value', function (): void {
    expect(MachineState::from('offline'))->toBe(MachineState::Offline);
});
