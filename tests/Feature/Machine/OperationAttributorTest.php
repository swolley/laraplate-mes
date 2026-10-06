<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\MES\Machine\Data\NormalizedSample;
use Modules\MES\Machine\Data\ResolvedSignal;
use Modules\MES\Machine\OperationAttributor;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(fn () => MesTestHelpers::makeCompany());

/**
 * @return array{target: ResolvedSignal, company_id: int, work_center_id: int}
 */
function attributionTarget(): array
{
    $signal = MachineSignal::factory()->create();
    $device = $signal->device;

    return [
        'target' => new ResolvedSignal($device, $signal, (int) $device->work_center_id, (int) $device->company_id),
        'company_id' => (int) $device->company_id,
        'work_center_id' => (int) $device->work_center_id,
    ];
}

function operationOn(array $ctx, string $start, ?string $end = null, ?ProductionOrder $order = null): ProductionOrderOperation
{
    $order ??= ProductionOrder::factory()->create(['company_id' => $ctx['company_id']]);

    return ProductionOrderOperation::factory()->create([
        'production_order_id' => $order->id,
        'work_center_id' => $ctx['work_center_id'],
        'actual_start_at' => $start,
        'actual_end_at' => $end,
    ]);
}

function sampleAt(string $ts, array $context = []): NormalizedSample
{
    return new NormalizedSample('d', 's', CarbonImmutable::parse($ts, config()->string('app.timezone')), 1, context: $context);
}

it('attributes to the single operation active at the sample time', function (): void {
    $ctx = attributionTarget();
    $operation = operationOn($ctx, '2026-10-05 08:00:00');

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00'));

    expect($attribution->production_order_operation_id)->toBe($operation->id)
        ->and($attribution->reason)->toBe('single_active');
});

it('attributes nothing to a sample from before the operation started', function (): void {
    $ctx = attributionTarget();
    operationOn($ctx, '2026-10-05 08:00:00');

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 07:59:59'));

    expect($attribution->production_order_operation_id)->toBeNull()
        ->and($attribution->reason)->toBe('none');
});

it('attributes late data by its own time, to an operation that has already ended', function (): void {
    $ctx = attributionTarget();
    $ended = operationOn($ctx, '2026-10-05 06:00:00', '2026-10-05 08:00:00');
    operationOn($ctx, '2026-10-05 08:30:00');

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 07:00:00'));

    expect($attribution->production_order_operation_id)->toBe($ended->id);
});

it('attributes nothing when two operations are active at the same time', function (): void {
    $ctx = attributionTarget();
    operationOn($ctx, '2026-10-05 08:00:00');
    operationOn($ctx, '2026-10-05 08:30:00');

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00'));

    expect($attribution->production_order_operation_id)->toBeNull()
        ->and($attribution->reason)->toBe('none');
});

it('attributes nothing to a sample dated in the future', function (): void {
    $ctx = attributionTarget();
    operationOn($ctx, now()->subHour()->toDateTimeString(), now()->addMinute()->toDateTimeString());

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], new NormalizedSample('d', 's', now()->addDay()->toImmutable(), 1));

    expect($attribution->production_order_operation_id)->toBeNull();
});

it('lets an explicit operation reference win over the single active operation', function (): void {
    $ctx = attributionTarget();
    operationOn($ctx, '2026-10-05 08:00:00');
    $other_order = ProductionOrder::factory()->create(['company_id' => $ctx['company_id']]);
    $referenced = ProductionOrderOperation::factory()->create(['production_order_id' => $other_order->id]);

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00', ['operation_ref' => (string) $referenced->id]));

    expect($attribution->production_order_operation_id)->toBe($referenced->id)
        ->and($attribution->reason)->toBe('explicit');
});

it('does not follow a reference into another company', function (): void {
    $ctx = attributionTarget();
    $foreign_order = ProductionOrder::factory()->create(['company_id' => MesTestHelpers::makeCompany()->id]);
    $foreign = ProductionOrderOperation::factory()->create(['production_order_id' => $foreign_order->id]);

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00', ['operation_ref' => (string) $foreign->id]));

    expect($attribution->production_order_operation_id)->toBeNull();
});

it('picks the single operation of the referenced order on the work center', function (): void {
    $ctx = attributionTarget();
    $order = ProductionOrder::factory()->create(['company_id' => $ctx['company_id'], 'number' => 'PO-REF-1']);
    $mine = operationOn($ctx, '2026-10-05 08:00:00', null, $order);
    operationOn($ctx, '2026-10-05 08:00:00');

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00', ['order_ref' => 'PO-REF-1']));
    $unknown = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00', ['order_ref' => 'PO-NOPE']));

    expect($attribution->production_order_operation_id)->toBe($mine->id)
        ->and($attribution->reason)->toBe('explicit')
        ->and($unknown->production_order_operation_id)->toBeNull();
});

it('attributes nothing when the operation reference does not belong to the order reference', function (): void {
    $ctx = attributionTarget();
    $order = ProductionOrder::factory()->create(['company_id' => $ctx['company_id'], 'number' => 'PO-A']);
    $elsewhere = ProductionOrderOperation::factory()->create();

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00', ['order_ref' => $order->number, 'operation_ref' => (string) $elsewhere->id]));

    expect($attribution->production_order_operation_id)->toBeNull();
});

it('uses the reference signal values of the message when the sample carries no context', function (): void {
    $ctx = attributionTarget();
    $referenced = ProductionOrderOperation::factory()->create(['production_order_id' => ProductionOrder::factory()->create(['company_id' => $ctx['company_id']])->id]);

    $attribution = resolve(OperationAttributor::class)->attribute($ctx['target'], sampleAt('2026-10-05 09:00:00'), null, (string) $referenced->id);

    expect($attribution->production_order_operation_id)->toBe($referenced->id);
});

it('attributes a prepared message from memory, with the same answers and no further queries', function (): void {
    $ctx = attributionTarget();
    $first = operationOn($ctx, '2026-10-05 08:00:00', '2026-10-05 09:00:00');
    $second = operationOn($ctx, '2026-10-05 09:30:00');
    $attributor = resolve(OperationAttributor::class);
    $attributor->prepare($ctx['company_id'], [$ctx['work_center_id']], CarbonImmutable::parse('2026-10-05 08:00:00', config()->string('app.timezone')), CarbonImmutable::parse('2026-10-05 10:00:00', config()->string('app.timezone')));

    DB::enableQueryLog();
    $answers = array_map(static fn (string $ts): ?int => $attributor->attribute($ctx['target'], sampleAt($ts))->production_order_operation_id, ['2026-10-05 08:30:00', '2026-10-05 09:15:00', '2026-10-05 09:45:00']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($answers)->toBe([$first->id, null, $second->id])
        ->and($queries)->toBe([]);
});

it('attributes nothing to a future sample, even when an operation is still running', function (): void {
    $ctx = attributionTarget();
    $running = operationOn($ctx, now()->subHour()->toDateTimeString());
    $future = new NormalizedSample('d', 's', now()->addDay()->toImmutable(), 1);
    $attributor = resolve(OperationAttributor::class);

    expect($attributor->attribute($ctx['target'], $future)->production_order_operation_id)->toBeNull()
        ->and($attributor->attribute($ctx['target'], new NormalizedSample('d', 's', now()->addDay()->toImmutable(), 1, context: ['operation_ref' => (string) $running->id]))->production_order_operation_id)->toBeNull()
        ->and($attributor->attribute($ctx['target'], new NormalizedSample('d', 's', now()->toImmutable(), 1))->production_order_operation_id)->toBe($running->id);
});
