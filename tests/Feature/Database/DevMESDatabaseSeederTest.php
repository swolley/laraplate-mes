<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Role;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Support\PermissionName;
use Modules\MES\Database\Seeders\DevMESDatabaseSeeder;
use Modules\MES\Enums\ProductionOrderOperationStatus as OpStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;

uses(RefreshDatabase::class);

test('dev seeder creates shop-floor demo data across statuses', function (): void {
    $this->seed(DevMESDatabaseSeeder::class);

    expect(WorkCenter::query()->withoutGlobalScopes()->where('code', 'like', 'WC-DEMO-%')->count())->toBe(4);
    expect(ProductionOrder::query()->withoutGlobalScopes()->where('number', 'like', 'PO-DEMO-%')->count())->toBe(5);

    foreach ([OpStatus::Planned, OpStatus::Ready, OpStatus::InProgress, OpStatus::Completed, OpStatus::Skipped] as $status) {
        expect(ProductionOrderOperation::query()->where('status', $status->value)->exists())
            ->toBeTrue("expected at least one operation in status {$status->value}");
    }
});

test('dev seeder is idempotent', function (): void {
    $this->seed(DevMESDatabaseSeeder::class);
    $this->seed(DevMESDatabaseSeeder::class);

    expect(WorkCenter::query()->withoutGlobalScopes()->where('code', 'like', 'WC-DEMO-%')->count())->toBe(4);
    expect(ProductionOrder::query()->withoutGlobalScopes()->where('number', 'like', 'PO-DEMO-%')->count())->toBe(5);
    expect(User::query()->where('email', 'mes.operator@laraplate.test')->count())->toBe(1);
});

test('seeded roles carry the right abilities and the users pass the mes scope', function (): void {
    $this->seed(DevMESDatabaseSeeder::class);

    $auth = app(AuthorizationService::class);
    $operator = User::query()->where('email', 'mes.operator@laraplate.test')->firstOrFail();
    $supervisor = User::query()->where('email', 'mes.supervisor@laraplate.test')->firstOrFail();

    expect($operator->hasRole('mes-operator'))->toBeTrue();
    expect($supervisor->hasRole('mes-supervisor'))->toBeTrue();

    // Both roles hold at least one `mes` permission → the scoped login admits them.
    expect($auth->userHasModuleAccess($operator, 'mes'))->toBeTrue();
    expect($auth->userHasModuleAccess($supervisor, 'mes'))->toBeTrue();

    $operatorPerms = Role::findByName('mes-operator', 'web')->permissions->pluck('name');
    $supervisorPerms = Role::findByName('mes-supervisor', 'web')->permissions->pluck('name');

    // The operator drives operations only; the supervisor also releases orders.
    expect($operatorPerms)->toContain(PermissionName::forClass(ProductionOrderOperation::class, 'start'));
    expect($operatorPerms->all())->not->toContain(PermissionName::forClass(ProductionOrder::class, 'release'));
    expect($supervisorPerms)->toContain(PermissionName::forClass(ProductionOrder::class, 'release'));
});
