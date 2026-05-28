<?php

declare(strict_types=1);

namespace Modules\MES\Database\Seeders;

use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Overrides\Seeder;

class MESDatabaseSeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, value: mixed, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            [
                'name' => 'mes.rate_limit',
                'value' => 60,
                'type' => SettingTypeEnum::Integer,
                'group_name' => 'mes',
                'description' => 'Maximum MES API requests per minute',
            ],
            [
                'name' => 'mes.lot_number_format',
                'value' => '{YEAR}{MONTH}{DAY}-{SEQ}',
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
        $this->seedSettingDefinitions(self::runtimeSettingDefinitions());
    }
}
