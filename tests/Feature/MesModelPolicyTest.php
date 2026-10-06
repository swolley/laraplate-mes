<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use Modules\MES\Authorization\MESPermissions;
use Modules\MES\Database\Seeders\MESDatabaseSeeder;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Enums\ProductionOrderStatus;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Policies\MesModelPolicy;

uses(RefreshDatabase::class);

function mesPolicySuperadmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    return $user;
}

function grantMesPolicyPermission(User $user, Model $record, string $operation): void
{
    $permission = PermissionName::forModel($record, $operation);
    Permission::findOrCreate($permission, 'web');
    $user->givePermissionTo($permission);
}

it('is the gate policy of every MES model with domain actions', function (string $model): void {
    expect(Gate::getPolicyFor($model))->toBeInstanceOf(MesModelPolicy::class);
})->with(array_keys(MESPermissions::operations()));

it('lets a superadmin act only when the record state allows it', function (string $ability, Closure $allowed, Closure $denied): void {
    $user = mesPolicySuperadmin();
    $policy = app(MesModelPolicy::class);

    expect($policy->{$ability}($user, $allowed()))->toBeTrue()
        ->and($policy->{$ability}($user, $denied()))->toBeFalse();
})->with([
    'release a draft order, not a released one' => ['release', fn () => ProductionOrder::factory()->create(), fn () => ProductionOrder::factory()->released()->create()],
    'complete a released order, not a draft one' => ['complete', fn () => ProductionOrder::factory()->released()->create(), fn () => ProductionOrder::factory()->create()],
    'complete an operation in progress, not a planned one' => ['complete', fn () => ProductionOrderOperation::factory()->inProgress()->create(), fn () => ProductionOrderOperation::factory()->create()],
    'cancel a released order, not a completed one' => ['cancel', fn () => ProductionOrder::factory()->released()->create(), fn () => ProductionOrder::factory()->create(['status' => ProductionOrderStatus::Completed->value])],
    'record consumption on a released order, not a cancelled one' => ['recordConsumption', fn () => ProductionOrder::factory()->released()->create(), fn () => ProductionOrder::factory()->create(['status' => ProductionOrderStatus::Cancelled->value])],
    'start a planned operation, not a completed one' => ['start', fn () => ProductionOrderOperation::factory()->create(), fn () => ProductionOrderOperation::factory()->create(['status' => ProductionOrderOperationStatus::Completed->value])],
    'skip a planned operation, not a completed one' => ['skip', fn () => ProductionOrderOperation::factory()->create(), fn () => ProductionOrderOperation::factory()->create(['status' => ProductionOrderOperationStatus::Completed->value])],
    'resolve an open non-conformance, not a closed one' => ['resolve', fn () => NonConformance::factory()->create(['status' => NonConformanceStatus::Open->value]), fn () => NonConformance::factory()->create(['status' => NonConformanceStatus::Closed->value])],
    'close a resolved non-conformance, not an open one' => ['close', fn () => NonConformance::factory()->create(['status' => NonConformanceStatus::Resolved->value]), fn () => NonConformance::factory()->create(['status' => NonConformanceStatus::Open->value])],
    'close an open downtime, not a closed one' => ['close', fn () => Downtime::factory()->create(), fn () => Downtime::factory()->closed()->create()],
    'open a downtime on a work center that is up, not one that is down' => ['openDowntime', fn () => WorkCenter::factory()->create(), function (): WorkCenter {
        $work_center = WorkCenter::factory()->create();
        Downtime::factory()->create(['work_center_id' => $work_center->id, 'company_id' => $work_center->company_id]);

        return $work_center;
    }],
    'cancel an in-progress order, not a completed one' => ['cancel', fn () => ProductionOrder::factory()->create(['status' => ProductionOrderStatus::InProgress->value]), fn () => ProductionOrder::factory()->create(['status' => ProductionOrderStatus::Completed->value])],
    'execute a quality check, not another record' => ['execute', fn () => QualityCheck::factory()->create(), fn () => Bom::factory()->create()],
    'explode a bom, not another record' => ['explode', fn () => Bom::factory()->create(), fn () => QualityCheck::factory()->create()],
    'trace a lot forward, not another record' => ['forwardTrace', fn () => LotNumber::factory()->create(), fn () => Bom::factory()->create()],
    'trace a lot backward, not another record' => ['backwardTrace', fn () => LotNumber::factory()->create(), fn () => Bom::factory()->create()],
]);

it('allows a user granted the specific permission', function (): void {
    $order = ProductionOrder::factory()->create();
    $user = User::factory()->create();
    grantMesPolicyPermission($user, $order, 'release');

    expect(app(MesModelPolicy::class)->release($user, $order))->toBeTrue()
        ->and(app(MesModelPolicy::class)->cancel($user, $order))->toBeFalse();
});

it('denies a user who lacks the permission even when the permission row exists', function (): void {
    $order = ProductionOrder::factory()->create();
    Permission::findOrCreate(PermissionName::forModel($order, 'release'), 'web');

    expect(app(MesModelPolicy::class)->release(User::factory()->create(), $order))->toBeFalse();
});

it('denies (fail-closed) when the permission row is absent', function (): void {
    expect(app(MesModelPolicy::class)->release(User::factory()->create(), ProductionOrder::factory()->create()))->toBeFalse();
});

it('denies a permitted user when the record state forbids the action', function (): void {
    $order = ProductionOrder::factory()->released()->create();
    $user = User::factory()->create();
    grantMesPolicyPermission($user, $order, 'release');

    expect(app(MesModelPolicy::class)->release($user, $order))->toBeFalse();
});

it('seeds every declared MES domain permission', function (): void {
    $this->seed(MESDatabaseSeeder::class);

    $expected = collect(MESPermissions::operations())
        ->flatMap(static fn (array $operations, string $model): array => array_map(
            static fn (string $operation): string => PermissionName::forClass($model, $operation),
            $operations,
        ))
        ->values();

    expect($expected)->toContain(PermissionName::forClass(ProductionOrder::class, 'release'))
        ->and(Permission::query()->whereIn('name', $expected)->pluck('name')->sort()->values()->all())
        ->toBe($expected->unique()->sort()->values()->all());
});
