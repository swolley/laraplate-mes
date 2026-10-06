<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineProfiles;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\MachineProfiles\Pages\CreateMachineProfile;
use Modules\MES\Filament\Resources\MachineProfiles\Pages\EditMachineProfile;
use Modules\MES\Filament\Resources\MachineProfiles\Pages\ListMachineProfiles;
use Modules\MES\Filament\Resources\MachineProfiles\Schemas\MachineProfileForm;
use Modules\MES\Filament\Resources\MachineProfiles\Tables\MachineProfilesTable;
use Modules\MES\Models\MachineProfile;
use Override;
use UnitEnum;

final class MachineProfileResource extends Resource
{
    #[Override]
    protected static ?string $model = MachineProfile::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 30;

    #[Override]
    protected static ?string $recordTitleAttribute = 'model';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/machine-profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return MachineProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MachineProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineProfiles::route('/'),
            'create' => CreateMachineProfile::route('/create'),
            'edit' => EditMachineProfile::route('/{record}/edit'),
        ];
    }
}
