<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

it('keeps a message id unique per source', function (): void {
    $message = MachineMessage::factory()->create(['message_id' => 'm-1']);
    $row = $message->only(['company_id', 'source_id', 'transport', 'payload', 'received_at']);
    $row['transport'] = $message->transport->value;
    $row['message_id'] = 'm-1';

    expect(fn () => DB::table('mes_machine_messages')->insert($row))->toThrow(UniqueConstraintViolationException::class);

    $other = MachineSource::factory()->create();
    expect(MachineMessage::factory()->create(['source_id' => $other->id, 'message_id' => 'm-1'])->exists)->toBeTrue();
});

it('prunes only processed messages past the retention', function (): void {
    config(['mes.machine.inbox_retention_days' => 7]);
    $old_processed = MachineMessage::factory()->processed()->create(['received_at' => now()->subDays(8)]);
    $old_failed = MachineMessage::factory()->failed()->create(['received_at' => now()->subDays(8)]);
    $old_pending = MachineMessage::factory()->create(['received_at' => now()->subDays(8)]);
    $recent_processed = MachineMessage::factory()->processed()->create(['received_at' => now()->subDay()]);

    $ids = new MachineMessage()->prunable()->pluck('id')->all();

    expect($ids)->toBe([$old_processed->id])
        ->and($old_failed->status)->toBe(MachineMessageStatus::Failed)
        ->and($old_pending->status)->toBe(MachineMessageStatus::Pending)
        ->and($recent_processed->id)->not->toBeIn($ids);
});

it('keeps an unmapped signal unique per source, device and key', function (): void {
    $unmapped = UnmappedSignal::factory()->create(['device_external_id' => 'press-07', 'signal_key' => 'temp']);

    expect(fn () => UnmappedSignal::factory()->create(['source_id' => $unmapped->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'temp']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($unmapped->seen_count)->toBe(0);
});

it('filters unresolved incidents', function (): void {
    $open = MachineIncident::factory()->create(['type' => MachineIncidentType::DeviceSilent->value]);
    MachineIncident::factory()->create(['resolved_at' => now()]);

    expect(MachineIncident::query()->unresolved()->pluck('id')->all())->toBe([$open->id])
        ->and($open->type)->toBe(MachineIncidentType::DeviceSilent)
        ->and($open->detail)->toBe([]);
});
