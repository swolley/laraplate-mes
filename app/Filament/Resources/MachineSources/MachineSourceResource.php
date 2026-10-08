<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\MachineSources\Pages\CreateMachineSource;
use Modules\MES\Filament\Resources\MachineSources\Pages\EditMachineSource;
use Modules\MES\Filament\Resources\MachineSources\Pages\ListMachineSources;
use Modules\MES\Filament\Resources\MachineSources\Schemas\MachineSourceForm;
use Modules\MES\Filament\Resources\MachineSources\Tables\MachineSourcesTable;
use Modules\MES\Models\MachineSource;
use Override;
use UnitEnum;

final class MachineSourceResource extends Resource
{
    #[Override]
    protected static ?string $model = MachineSource::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 10;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/machine-sources';
    }

    public static function form(Schema $schema): Schema
    {
        return MachineSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MachineSourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineSources::route('/'),
            'create' => CreateMachineSource::route('/create'),
            'edit' => EditMachineSource::route('/{record}/edit'),
        ];
    }
}
