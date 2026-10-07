<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\QualityCheckStatus;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityCheckMeasurement;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\Routing;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Models\UnattributedMeasurement;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\QualityCheckPlanner;
use Modules\MES\Services\UnattributedMeasurementAssigner;
use Modules\MES\Services\UnattributedMeasurementAttacher;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MesTestHelpers::makeCompany();
});

/**
 * An operation of a routing step that has a quality plan with one characteristic and a measuring signal.
 *
 * @return array{operation: ProductionOrderOperation, plan: QualityPlan, characteristic: QualityPlanCharacteristic, signal: MachineSignal}
 */
function plannedOperation(int $required_samples = 1): array
{
    $work_center = WorkCenter::factory()->create();
    $order = ProductionOrder::factory()->released()->create(['quantity_planned' => 5]);
    $routing = Routing::factory()->create(['item_id' => $order->item_id, 'valid_from' => now()->subDay()->toDateString()]);
    $step = RoutingOperation::query()->create(['routing_id' => $routing->id, 'work_center_id' => $work_center->id, 'sequence' => 10, 'description' => 'Turning', 'setup_time_minutes' => 1, 'cycle_time_minutes' => 1]);
    $plan = QualityPlan::factory()->create(['item_id' => $order->item_id, 'routing_operation_id' => $step->id, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => null, 'is_active' => true]);
    $characteristic = QualityPlanCharacteristic::factory()->create(['quality_plan_id' => $plan->id, 'characteristic' => 'diameter', 'lower_limit' => 9, 'upper_limit' => 11, 'required_samples' => $required_samples]);
    $device = MachineDevice::factory()->create(['work_center_id' => $work_center->id]);
    $signal = MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'dia', 'role' => SignalRole::Measurement->value, 'config' => [], 'quality_plan_characteristic_id' => $characteristic->id]);
    $operation = ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'work_center_id' => $work_center->id, 'routing_operation_id' => $step->id]);

    return ['operation' => $operation, 'plan' => $plan, 'characteristic' => $characteristic, 'signal' => $signal];
}

function waiting(array $rig, string $time, float $value, ?int $operation_id = null): UnattributedMeasurement
{
    return UnattributedMeasurement::factory()->create([
        'signal_id' => $rig['signal']->id,
        'device_id' => $rig['signal']->device_id,
        'work_center_id' => $rig['operation']->work_center_id,
        'production_order_operation_id' => $operation_id ?? $rig['operation']->id,
        'ts' => "2026-10-05 {$time}",
        'value' => $value,
    ]);
}

it('attaches the waiting measurements of an operation to the check the planner creates, and resolves it', function (): void {
    $rig = plannedOperation(2);
    $first = waiting($rig, '08:00:00', 10);
    $second = waiting($rig, '08:01:00', 10.5);
    $other = waiting($rig, '08:02:00', 10, ProductionOrderOperation::factory()->create(['work_center_id' => $rig['operation']->work_center_id])->id);

    $check = resolve(QualityCheckPlanner::class)->forOperation($rig['operation']->fresh());

    expect($check)->toBeInstanceOf(QualityCheck::class)
        ->and(QualityCheckMeasurement::query()->where('quality_check_id', $check->id)->count())->toBe(2)
        ->and($check->fresh()->status)->toBe(QualityCheckStatus::Passed)
        ->and($first->fresh()->assigned_at)->not->toBeNull()
        ->and($second->fresh()->assigned_at)->not->toBeNull()
        ->and($other->fresh()->assigned_at)->toBeNull();
});

it('leaves the check pending when the waiting measurements are not enough, and attaches nothing twice', function (): void {
    $rig = plannedOperation(3);
    waiting($rig, '08:00:00', 10);
    $planner = resolve(QualityCheckPlanner::class);

    $check = $planner->forOperation($rig['operation']->fresh());
    $planner->forOperation($rig['operation']->fresh());
    $again = resolve(UnattributedMeasurementAttacher::class)->attachFor($check);

    expect($check->fresh()->status)->toBe(QualityCheckStatus::Pending)
        ->and($again)->toBe(0)
        ->and(QualityCheckMeasurement::query()->count())->toBe(1);
});

it('does not attach the measurement of a characteristic that is not in the plan of the check', function (): void {
    $rig = plannedOperation();
    $foreign = MachineSignal::factory()->create(['device_id' => $rig['signal']->device_id, 'key' => 'foreign', 'role' => SignalRole::Measurement->value, 'config' => [], 'quality_plan_characteristic_id' => QualityPlanCharacteristic::factory()->create()->id]);
    $row = UnattributedMeasurement::factory()->create(['signal_id' => $foreign->id, 'device_id' => $foreign->device_id, 'work_center_id' => $rig['operation']->work_center_id, 'production_order_operation_id' => $rig['operation']->id, 'ts' => '2026-10-05 08:00:00']);

    resolve(QualityCheckPlanner::class)->forOperation($rig['operation']->fresh());

    expect($row->fresh()->assigned_at)->toBeNull();
});

it('assigns a measurement by hand to a check, resolving a complete one', function (): void {
    $rig = plannedOperation();
    $row = waiting($rig, '08:00:00', 10, null);
    $row->forceFill(['production_order_operation_id' => null])->save();
    $check = QualityCheck::factory()->create(['production_order_id' => $rig['operation']->production_order_id, 'production_order_operation_id' => $rig['operation']->id, 'quality_plan_id' => $rig['plan']->id]);

    $measurement = resolve(UnattributedMeasurementAssigner::class)->assign($row, $check);

    expect($measurement->quality_check_id)->toBe($check->id)
        ->and($measurement->source)->toBe('machine')
        ->and($measurement->machine_signal_id)->toBe($rig['signal']->id)
        ->and($row->fresh()->assigned_at)->not->toBeNull()
        ->and($check->fresh()->status)->toBe(QualityCheckStatus::Passed);
});

it('refuses an assignment to a check whose plan lacks the characteristic, to another company, and of an assigned row', function (): void {
    $rig = plannedOperation();
    $row = waiting($rig, '08:00:00', 10);
    $assigner = resolve(UnattributedMeasurementAssigner::class);
    $foreign_plan = QualityCheck::factory()->create(['quality_plan_id' => QualityPlan::factory()->create()->id]);
    $other_company = QualityCheck::factory()->create(['quality_plan_id' => $rig['plan']->id]);
    $other_company->forceFill(['company_id' => Modules\ERP\Models\Company::factory()->create()->id])->saveQuietly();

    expect(fn () => $assigner->assign($row, $foreign_plan))->toThrow(DomainException::class)
        ->and(fn () => $assigner->assign($row, $other_company))->toThrow(DomainException::class);

    $good = QualityCheck::factory()->create(['production_order_id' => $rig['operation']->production_order_id, 'quality_plan_id' => $rig['plan']->id]);
    $assigner->assign($row, $good);

    expect(fn () => $assigner->assign($row->fresh(), $good))->toThrow(DomainException::class);
});

it('attaches what it can and does not fail when one waiting row cannot be assigned', function (): void {
    $rig = plannedOperation(2);
    $bad = waiting($rig, '08:00:00', 10);
    $bad->forceFill(['company_id' => Modules\ERP\Models\Company::factory()->create()->id])->saveQuietly();
    waiting($rig, '08:01:00', 10);
    $check = QualityCheck::factory()->create(['production_order_id' => $rig['operation']->production_order_id, 'production_order_operation_id' => $rig['operation']->id, 'quality_plan_id' => $rig['plan']->id]);

    $attached = resolve(UnattributedMeasurementAttacher::class)->attachFor($check);

    expect($attached)->toBe(1)
        ->and($bad->fresh()->assigned_at)->toBeNull()
        ->and(QualityCheckMeasurement::query()->where('quality_check_id', $check->id)->count())->toBe(1);
});
