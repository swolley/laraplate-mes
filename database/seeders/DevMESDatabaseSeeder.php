<?php

declare(strict_types=1);

namespace Modules\MES\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Support\PermissionName;
use Modules\MES\Enums\ProductionOrderOperationStatus as OpStatus;
use Modules\MES\Enums\ProductionOrderStatus as OrderStatus;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\WorkCenter;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dev fixture for the MES SPA: a handful of work centers, production orders
 * spread across statuses each with operations in mixed states, plus a
 * `mes-supervisor` and `mes-operator` role (with the matching users) so the
 * module-scoped login and the operation/order domain actions can be exercised
 * end to end.
 */
final class DevMESDatabaseSeeder extends Seeder
{
    private const DEMO_MARK = 'WC-DEMO-A';

    public function run(): void
    {
        Model::unguarded(function (): void {
            $this->seedRolesAndUsers();
            $this->seedShopFloor();
        });
    }

    private function seedShopFloor(): void
    {
        if (WorkCenter::query()->withoutGlobalScopes()->where('code', self::DEMO_MARK)->exists()) {
            $this->command?->line('    - MES shop-floor demo data already present');

            return;
        }

        $this->command?->line('    - seeding MES shop-floor demo data');

        /** @var list<WorkCenter> $workCenters */
        $workCenters = [];

        foreach ([['WC-DEMO-A', 'Taglio'], ['WC-DEMO-B', 'Foratura'], ['WC-DEMO-C', 'Assemblaggio'], ['WC-DEMO-D', 'Collaudo']] as [$code, $name]) {
            $workCenters[] = WorkCenter::factory()->create(['code' => $code, 'name' => $name, 'is_active' => true]);
        }

        /**
         * @var array<int, array{status: OrderStatus, ops: list<OpStatus>}> $blueprint
         */
        $blueprint = [
            ['status' => OrderStatus::Draft, 'ops' => [OpStatus::Planned, OpStatus::Planned, OpStatus::Planned]],
            ['status' => OrderStatus::Released, 'ops' => [OpStatus::Ready, OpStatus::Planned, OpStatus::Planned]],
            ['status' => OrderStatus::Released, 'ops' => [OpStatus::InProgress, OpStatus::Ready, OpStatus::Planned]],
            ['status' => OrderStatus::InProgress, 'ops' => [OpStatus::Completed, OpStatus::InProgress, OpStatus::Ready]],
            ['status' => OrderStatus::InProgress, 'ops' => [OpStatus::Completed, OpStatus::Completed, OpStatus::Skipped, OpStatus::InProgress]],
        ];

        foreach ($blueprint as $index => $definition) {
            $order = ProductionOrder::factory()->create([
                'number' => sprintf('PO-DEMO-%02d', $index + 1),
                'status' => $definition['status']->value,
            ]);

            foreach ($definition['ops'] as $sequence => $status) {
                ProductionOrderOperation::factory()->create([
                    'production_order_id' => $order->id,
                    'work_center_id' => $workCenters[$sequence % count($workCenters)]->id,
                    'sequence' => $sequence + 1,
                    'status' => $status->value,
                    'description' => sprintf('%s op. %d', $order->number, $sequence + 1),
                ]);
            }
        }
    }

    private function seedRolesAndUsers(): void
    {
        $supervisorPermissions = [
            PermissionName::forClass(ProductionOrder::class, 'select'),
            PermissionName::forClass(ProductionOrder::class, 'release'),
            PermissionName::forClass(ProductionOrder::class, 'complete'),
            PermissionName::forClass(ProductionOrder::class, 'cancel'),
            PermissionName::forClass(ProductionOrderOperation::class, 'select'),
            PermissionName::forClass(ProductionOrderOperation::class, 'start'),
            PermissionName::forClass(ProductionOrderOperation::class, 'complete'),
            PermissionName::forClass(ProductionOrderOperation::class, 'skip'),
            PermissionName::forClass(WorkCenter::class, 'select'),
        ];

        $operatorPermissions = [
            PermissionName::forClass(ProductionOrderOperation::class, 'select'),
            PermissionName::forClass(ProductionOrderOperation::class, 'start'),
            PermissionName::forClass(ProductionOrderOperation::class, 'complete'),
            PermissionName::forClass(ProductionOrderOperation::class, 'skip'),
            PermissionName::forClass(WorkCenter::class, 'select'),
        ];

        // Bypass Spatie Permission::findOrCreate (same rationale as DevSAO /
        // MESDatabaseSeeder): avoid hydrating a corrupt spatie.permission.cache.
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permission = new Permission;

        foreach (array_unique([...$supervisorPermissions, ...$operatorPermissions]) as $name) {
            $permission->newQuery()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $registrar->forgetCachedPermissions();

        $supervisor = Role::query()->firstOrCreate([
            'name' => 'mes-supervisor',
            'guard_name' => 'web',
        ]);
        $supervisor->givePermissionTo($supervisorPermissions);

        $operator = Role::query()->firstOrCreate([
            'name' => 'mes-operator',
            'guard_name' => 'web',
        ]);
        $operator->givePermissionTo($operatorPermissions);

        $this->ensureUser('mes.supervisor@laraplate.test', 'MES Supervisor', $supervisor->name);
        $this->ensureUser('mes.operator@laraplate.test', 'MES Operator', $operator->name);

        $this->command?->line('    - MES roles/users (mes-supervisor, mes-operator) <fg=green>ready</> (password: password)');
    }

    private function ensureUser(string $email, string $name, string $role): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_first_login' => false,
            ]);
        }

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
