<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\MachineDevices\Pages\CreateMachineDevice;
use Modules\MES\Filament\Resources\MachineDevices\Pages\EditMachineDevice;
use Modules\MES\Filament\Resources\MachineDevices\Pages\ListMachineDevices;
use Modules\MES\Filament\Resources\MachineDevices\Schemas\MachineDeviceForm;
use Modules\MES\Filament\Resources\MachineDevices\Tables\MachineDevicesTable;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Filament\Resources\MachineDevices\RelationManagers\SignalsRelationManager;
use Override;
use UnitEnum;

final class MachineDeviceResource extends Resource
{
    #[Override]
    protected static ?string $model = MachineDevice::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 20;

    #[Override]
    protected static ?string $recordTitleAttribute = 'external_id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/machine-devices';
    }

    public static function form(Schema $schema): Schema
    {
        return MachineDeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MachineDevicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [SignalsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineDevices::route('/'),
            'create' => CreateMachineDevice::route('/create'),
            'edit' => EditMachineDevice::route('/{record}/edit'),
        ];
    }
}
