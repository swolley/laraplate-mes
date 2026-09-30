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
