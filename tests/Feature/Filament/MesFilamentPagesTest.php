<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Filament\Resources\Boms\Pages\EditBom;
use Modules\MES\Filament\Resources\Boms\Pages\ListBoms;
use Modules\MES\Filament\Resources\Downtimes\Pages\ListDowntimes;
use Modules\MES\Filament\Resources\NonConformances\Pages\ListNonConformances;
use Modules\MES\Filament\Resources\ProductionOrders\Pages\EditProductionOrder;
use Modules\MES\Filament\Resources\ProductionOrders\Pages\ListProductionOrders;
use Modules\MES\Filament\Resources\ProductionOrders\RelationManagers\LotNumbersRelationManager;
use Modules\MES\Filament\Resources\ProductionOrders\RelationManagers\MaterialConsumptionsRelationManager;
use Modules\MES\Filament\Resources\ProductionOrders\RelationManagers\OperationsRelationManager;
use Modules\MES\Filament\Resources\ProductionOrders\RelationManagers\QualityChecksRelationManager;
use Modules\MES\Filament\Resources\QualityChecks\Pages\ListQualityChecks;
use Modules\MES\Filament\Resources\QualityPlans\Pages\ListQualityPlans;
use Modules\MES\Filament\Resources\Routings\Pages\ListRoutings;
use Modules\MES\Filament\Resources\Shifts\Pages\ListShifts;
use Modules\MES\Filament\Resources\WorkCenters\Pages\CreateWorkCenter;
use Modules\MES\Filament\Resources\WorkCenters\Pages\EditWorkCenter;
use Modules\MES\Filament\Resources\WorkCenters\Pages\ListWorkCenters;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\MaterialConsumption;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlan;
use Modules\MES\Models\Routing;
use Modules\MES\Models\Shift;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Models\WorkCenterCalendar;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $superadmin */
    $superadmin = App\Models\User::query()->create(User::factory()->raw());
    $superadmin->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    $this->actingAs($superadmin);
    Filament::setCurrentPanel('admin');
});

it('renders the list page with its records for a superadmin', function (string $page, Closure $make): void {
    /** @var Model $record */
    $record = $make();

    $list = Livewire::test($page)->assertOk();

    expect($list->instance()->getTableRecords()->modelKeys())->toContain($record->getKey());
})->with([
    'work centers' => [ListWorkCenters::class, fn (): WorkCenter => WorkCenter::factory()->create()],
    'boms' => [ListBoms::class, fn (): Bom => Bom::factory()->create()],
    'routings' => [ListRoutings::class, fn (): Routing => Routing::factory()->create()],
    'production orders' => [ListProductionOrders::class, fn (): ProductionOrder => ProductionOrder::factory()->create()],
    'quality plans' => [ListQualityPlans::class, fn (): QualityPlan => QualityPlan::factory()->create()],
    'quality checks' => [ListQualityChecks::class, fn (): QualityCheck => QualityCheck::factory()->create()],
    'non-conformances' => [ListNonConformances::class, fn (): NonConformance => NonConformance::factory()->create()],
    'downtimes' => [ListDowntimes::class, fn (): Downtime => Downtime::factory()->create()],
    'shifts' => [ListShifts::class, fn (): Shift => Shift::factory()->create()],
]);

it('creates a work center with its working calendar from the form', function (): void {
    $company = MesTestHelpers::makeCompany();

    Livewire::test(CreateWorkCenter::class)
        ->fillForm([
            'company_id' => $company->id,
            'code' => 'WC-FORM',
            'name' => 'Press',
            'type' => 'machine',
            'capacity_per_hour' => 12,
            'capacity_uom' => 'pcs',
            'calendar' => [
                ['day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '12:00'],
                ['day_of_week' => 0, 'start_time' => '13:00', 'end_time' => '17:00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $work_center = WorkCenter::withoutGlobalScopes()->where('code', 'WC-FORM')->sole();

    $starts = $work_center->calendar()->orderBy('start_time')->pluck('start_time')
        ->map(static fn (string $time): string => mb_substr($time, 0, 5))
        ->all();

    expect($starts)->toBe(['08:00', '13:00']);
});

it('edits and removes calendar slots of a work center', function (): void {
    $work_center = WorkCenter::factory()->create();
    $kept = WorkCenterCalendar::factory()->create(['work_center_id' => $work_center->id, 'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '16:00:00']);
    $removed = WorkCenterCalendar::factory()->create(['work_center_id' => $work_center->id, 'day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '16:00:00']);

    $page = Livewire::test(EditWorkCenter::class, ['record' => $work_center->getKey()])->assertOk();

    $slots = $page->get('data.calendar');
    unset($slots["record-{$removed->id}"]);
    $slots["record-{$kept->id}"]['end_time'] = '18:00';

    $page->set('data.calendar', $slots)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($work_center->calendar()->pluck('id')->all())->toBe([$kept->id])
        ->and(mb_substr((string) $kept->fresh()->end_time, 0, 5))->toBe('18:00');
});

it('rejects a calendar slot that ends before it starts', function (): void {
    Livewire::test(CreateWorkCenter::class)
        ->fillForm([
            'company_id' => MesTestHelpers::makeCompany()->id,
            'code' => 'WC-BAD',
            'name' => 'Press',
            'type' => 'machine',
            'capacity_per_hour' => 12,
            'capacity_uom' => 'pcs',
            'calendar' => [
                ['day_of_week' => 0, 'start_time' => '12:00', 'end_time' => '08:00'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(WorkCenter::withoutGlobalScopes()->where('code', 'WC-BAD')->exists())->toBeFalse();
});

it('edits bom lines from the form and versions each edited line', function (): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);
    app(PerModelSettingResolver::class)->flush();
    BomLine::resetVersionStrategyCache();

    $bom = Bom::factory()->create();
    $line = BomLine::factory()->create(['bom_id' => $bom->id, 'quantity' => 2]);
    $component = MesTestHelpers::makeItem($bom->company_id);
    $versions_before = $line->versions()->count();

    $page = Livewire::test(EditBom::class, ['record' => $bom->getKey()])->assertOk();

    $lines = $page->get('data.bomLines');
    $lines["record-{$line->id}"]['quantity'] = 5;
    $lines['new-line'] = [
        'item_id' => $component->id,
        'quantity' => 1,
        'uom' => 'pcs',
        'consumption_method' => 'manual',
        'routing_operation_id' => null,
    ];

    $page->set('data.bomLines', $lines)
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $line->fresh()->quantity)->toBe(5.0)
        ->and($line->versions()->count())->toBe($versions_before + 1)
        ->and($bom->bomLines()->pluck('item_id')->all())->toContain($component->id);
});

it('shows the production order records read-only on the edit page', function (): void {
    $order = ProductionOrder::factory()->create();
    $operation = ProductionOrderOperation::factory()->create(['production_order_id' => $order->id]);

    Livewire::test(EditProductionOrder::class, ['record' => $order->getKey()])
        ->assertOk()
        ->assertSeeLivewire(OperationsRelationManager::class);

    $manager = Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditProductionOrder::class])
        ->assertOk();

    expect($manager->instance()->getTableRecords()->modelKeys())->toBe([$operation->getKey()])
        ->and($manager->instance()->isReadOnly())->toBeTrue();
});

it('renders every read-only production order relation manager', function (string $manager, Closure $make): void {
    $order = ProductionOrder::factory()->create();

    /** @var Model $record */
    $record = $make($order);

    $component = Livewire::test($manager, ['ownerRecord' => $order, 'pageClass' => EditProductionOrder::class])->assertOk();

    expect($component->instance()->getTableRecords()->modelKeys())->toBe([$record->getKey()])
        ->and($component->instance()->isReadOnly())->toBeTrue();
})->with([
    'material consumptions' => [MaterialConsumptionsRelationManager::class, fn (ProductionOrder $order): MaterialConsumption => MaterialConsumption::factory()->create(['production_order_id' => $order->id])],
    'quality checks' => [QualityChecksRelationManager::class, fn (ProductionOrder $order): QualityCheck => QualityCheck::factory()->create(['production_order_id' => $order->id])],
    'lot numbers' => [LotNumbersRelationManager::class, fn (ProductionOrder $order): LotNumber => LotNumber::factory()->create(['production_order_id' => $order->id])],
]);

it('releases, completes and cancels a production order from its edit page', function (): void {
    $draft = ProductionOrder::factory()->create();

    Livewire::test(EditProductionOrder::class, ['record' => $draft->getKey()])
        ->assertActionVisible('release')
        ->assertActionHidden('complete')
        ->callAction('release')
        ->assertNotified();
    expect($draft->fresh()->status)->toBe(ProductionOrderStatus::Released);

    Livewire::test(EditProductionOrder::class, ['record' => $draft->getKey()])
        ->assertActionHidden('release')
        ->assertActionVisible('complete')
        ->callAction('complete', ['quantity_produced' => 5])
        ->assertNotified();
    expect($draft->fresh()->status)->toBe(ProductionOrderStatus::Completed)
        ->and((float) $draft->fresh()->quantity_produced)->toBe(5.0);

    $other = ProductionOrder::factory()->released()->create();

    Livewire::test(EditProductionOrder::class, ['record' => $other->getKey()])
        ->assertActionVisible('cancel')
        ->callAction('cancel')
        ->assertNotified();
    expect($other->fresh()->status)->toBe(ProductionOrderStatus::Cancelled);

    Livewire::test(EditProductionOrder::class, ['record' => $other->getKey()])
        ->assertActionHidden('cancel')
        ->assertActionHidden('release')
        ->assertActionHidden('complete');
});

it('reports a refused transition instead of failing the page', function (): void {
    $order = ProductionOrder::factory()->released()->create();
    $operation = ProductionOrderOperation::factory()->inProgress()->create(['production_order_id' => $order->id]);

    Livewire::test(EditProductionOrder::class, ['record' => $order->getKey()])
        ->callAction('complete', ['quantity_produced' => 5])
        ->assertNotified();

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Released)
        ->and($operation->fresh()->status)->toBe(ProductionOrderOperationStatus::InProgress);
});

it('hides the transition actions from a user without the domain permissions', function (): void {
    $order = ProductionOrder::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(EditProductionOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('release')
        ->assertActionHidden('cancel');
});

/**
 * @return array{0: WorkCenter, 1: Modules\MES\Models\MachineDevice}
 */
function connectedWorkCenterForFilament(): array
{
    $work_center = WorkCenter::factory()->create();
    $device = Modules\MES\Models\MachineDevice::factory()->create(['work_center_id' => $work_center->id, 'company_id' => $work_center->company_id]);
    Modules\MES\Models\MachineSignal::factory()->create(['device_id' => $device->id, 'key' => 'state', 'role' => Modules\MES\Enums\SignalRole::State->value, 'config' => ['map' => ['RUN' => 'running']]]);

    return [$work_center, $device];
}

it('saves the micro-stop threshold and the downtime states of a work center', function (): void {
    $work_center = WorkCenter::factory()->create();

    Livewire::test(EditWorkCenter::class, ['record' => $work_center->getKey()])
        ->fillForm(['micro_stop_threshold_seconds' => 90, 'downtime_states' => ['fault', 'stopped']])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = WorkCenter::withoutGlobalScopes()->findOrFail($work_center->id);
    expect($fresh->micro_stop_threshold_seconds)->toBe(90)
        ->and($fresh->downtime_states)->toBe(['fault', 'stopped']);
});

it('rejects a negative micro-stop threshold', function (): void {
    $work_center = WorkCenter::factory()->create();

    Livewire::test(EditWorkCenter::class, ['record' => $work_center->getKey()])
        ->fillForm(['micro_stop_threshold_seconds' => -1])
        ->call('save')
        ->assertHasFormErrors(['micro_stop_threshold_seconds']);
});

it('keeps the cause and notes of a machine downtime editable and its times locked', function (): void {
    [$work_center, $device] = connectedWorkCenterForFilament();
    $downtime = Downtime::writingAsMachine(static fn () => Downtime::factory()->create([
        'company_id' => $work_center->company_id,
        'work_center_id' => $work_center->id,
        'source' => Modules\MES\Enums\DowntimeSource::Machine->value,
        'machine_device_id' => $device->id,
        'cause' => Modules\MES\Enums\DowntimeCause::Unclassified->value,
        'started_at' => '2026-10-05 08:00:00',
        'ended_at' => '2026-10-05 09:00:00',
    ]));

    Livewire::test(Modules\MES\Filament\Resources\Downtimes\Pages\EditDowntime::class, ['record' => $downtime->getKey()])
        ->assertFormFieldIsDisabled('started_at')
        ->assertFormFieldIsDisabled('ended_at')
        ->assertFormFieldIsEnabled('cause')
        ->fillForm(['cause' => 'breakdown', 'notes' => 'Belt snapped'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = Downtime::withoutGlobalScopes()->findOrFail($downtime->id);
    expect($fresh->cause)->toBe(Modules\MES\Enums\DowntimeCause::Breakdown)
        ->and($fresh->notes)->toBe('Belt snapped')
        ->and($fresh->ended_at->format('H:i'))->toBe('09:00');

    expect(fn () => $fresh->update(['ended_at' => '2026-10-05 10:00:00']))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('lists machine downtimes with their source', function (): void {
    [$work_center, $device] = connectedWorkCenterForFilament();
    $downtime = Downtime::writingAsMachine(static fn () => Downtime::factory()->create([
        'company_id' => $work_center->company_id,
        'work_center_id' => $work_center->id,
        'source' => Modules\MES\Enums\DowntimeSource::Machine->value,
        'machine_device_id' => $device->id,
        'alarm_code' => 'E42',
        'started_at' => '2026-10-05 08:00:00',
        'ended_at' => '2026-10-05 09:00:00',
    ]));

    $list = Livewire::test(ListDowntimes::class)->assertOk();
    expect($list->instance()->getTableRecords()->modelKeys())->toContain($downtime->getKey());

    $list->assertTableColumnStateSet('source', Modules\MES\Enums\DowntimeSource::Machine, $downtime)
        ->assertTableColumnStateSet('alarm_code', 'E42', $downtime)
        ->assertTableColumnStateSet('device.external_id', $device->external_id, $downtime);
});

it('refuses a manual downtime for a connected work center and stores nothing', function (): void {
    [$work_center] = connectedWorkCenterForFilament();

    Livewire::test(Modules\MES\Filament\Resources\Downtimes\Pages\CreateDowntime::class)
        ->fillForm([
            'company_id' => $work_center->company_id,
            'work_center_id' => $work_center->id,
            'cause' => 'breakdown',
            'started_at' => '2026-10-05 08:00:00',
        ])
        ->call('create');

    expect(Downtime::withoutGlobalScopes()->where('work_center_id', $work_center->id)->exists())->toBeFalse();
});

it('stores no list of downtime states when none is ticked, so the defaults apply', function (): void {
    $work_center = WorkCenter::factory()->create();

    Livewire::test(EditWorkCenter::class, ['record' => $work_center->getKey()])
        ->fillForm(['downtime_states' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = WorkCenter::withoutGlobalScopes()->findOrFail($work_center->id);
    expect($fresh->downtime_states)->toBeNull()
        ->and($fresh->isDowntimeState(Modules\MES\Enums\MachineState::Fault))->toBeTrue();
});

it('never treats offline or running as a downtime state, whatever the list says', function (): void {
    $work_center = WorkCenter::factory()->create(['downtime_states' => ['fault', 'offline', 'running']]);

    expect($work_center->isDowntimeState(Modules\MES\Enums\MachineState::Offline))->toBeFalse()
        ->and($work_center->isDowntimeState(Modules\MES\Enums\MachineState::Running))->toBeFalse()
        ->and($work_center->isDowntimeState(Modules\MES\Enums\MachineState::Fault))->toBeTrue();
});

it('declares the quantities of an operation from the operations table, with an audit row', function (): void {
    $order = ProductionOrder::factory()->create();
    $operation = ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'machine_good_quantity' => 95, 'machine_scrap_quantity' => 5, 'declared_good_quantity' => 95]);

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditProductionOrder::class])
        ->assertTableColumnExists('machine_good_quantity')
        ->assertTableColumnExists('declared_good_quantity')
        ->assertTableColumnExists('target_reached_at')
        ->callTableAction('declare', $operation, ['declared_good_quantity' => 92, 'declared_scrap_quantity' => 8])
        ->assertHasNoTableActionErrors();

    $fresh = ProductionOrderOperation::query()->findOrFail($operation->id);
    expect((float) $fresh->declared_good_quantity)->toBe(92.0)
        ->and((float) $fresh->declared_scrap_quantity)->toBe(8.0)
        ->and($fresh->quantityAudits()->count())->toBe(2);
});

it('assigns the unattributed counts of a time range to an operation from the operations table', function (): void {
    $order = ProductionOrder::factory()->create(['quantity_planned' => 1000]);
    $operation = ProductionOrderOperation::factory()->create(['production_order_id' => $order->id]);
    $count = Modules\MES\Models\MachineCount::factory()->create(['work_center_id' => $operation->work_center_id, 'ts' => '2026-10-05 08:30:00', 'good' => 12]);

    Livewire::test(OperationsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditProductionOrder::class])
        ->callTableAction('assign_counts', $operation, ['from' => '2026-10-05 08:00:00', 'to' => '2026-10-05 09:00:00'])
        ->assertHasNoTableActionErrors();

    expect($count->fresh()->production_order_operation_id)->toBe($operation->id)
        ->and((float) $operation->fresh()->machine_good_quantity)->toBe(12.0);
});

it('proposes the declared good quantity of the last operation when completing an order', function (): void {
    $order = ProductionOrder::factory()->create(['status' => Modules\MES\Enums\ProductionOrderStatus::InProgress->value]);
    ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'sequence' => 10, 'declared_good_quantity' => 40]);
    ProductionOrderOperation::factory()->create(['production_order_id' => $order->id, 'sequence' => 20, 'declared_good_quantity' => 37]);

    Livewire::test(EditProductionOrder::class, ['record' => $order->getKey()])
        ->mountAction('complete')
        ->assertActionDataSet(['quantity_produced' => 37]);
});
