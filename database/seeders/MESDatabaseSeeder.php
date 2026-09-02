<?php

declare(strict_types=1);

namespace Modules\MES\Database\Seeders;

use Modules\Core\Authorization\PermissionManifest;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\SeedDefinition;
use Modules\Core\Seeding\SeedReconciler;
use Spatie\Permission\PermissionRegistrar;

class MESDatabaseSeeder extends Seeder
{
    /**
     * Every row carries the same column set (`encrypted`, `choices` included even when null):
     * {@see SeedReconciler} batches these rows into a single `upsert()`, which requires a uniform
     * column set across the whole batch — a mismatch surfaces as a binding-count SQL error.
     *
     * @return array<int, array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            [
                'name' => 'mes.rate_limit',
                'value' => 60,
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::Integer,
                'group_name' => 'mes',
                'description' => 'Maximum MES API requests per minute',
            ],
            [
                'name' => 'mes.lot_number_format',
                'value' => '{YEAR}{MONTH}{DAY}-{SEQ}',
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::String,
                'group_name' => 'mes',
                'description' => 'Lot number generation format',
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $outcome = app(SeedReconciler::class)->reconcile(
            SeedDefinition::for(Setting::class)
                ->identity(['name'])
                ->structural(['type', 'group_name', 'description', 'choices'])
                ->initial(['value'])
                ->ownedBy('MES')
                ->rows(self::runtimeSettingDefinitions()),
        );

        $this->command?->line(
            '    - created ' . count($outcome->created) . ', realigned ' . count($outcome->realigned) . ", unchanged {$outcome->unchanged}",
        );

        $this->ensureDomainPermissions();
    }

    /**
     * Materializes this module's slice of the permission manifest.
     *
     * `permission:refresh` creates the same names from the same declaration and
     * runs before every module seeder in the graph; this keeps a module seeded in
     * isolation (as the test suites do) self-sufficient.
     */
    private function ensureDomainPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = new Permission;

        foreach (app(PermissionManifest::class)->namesFor('MES') as $name) {
            $permission->newQuery()->firstOrCreate(['name' => $name]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->line('    - MES domain permissions <fg=green>updated</>');
    }
}
