<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Shifts;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\Shifts\Pages\CreateShift;
use Modules\MES\Filament\Resources\Shifts\Pages\EditShift;
use Modules\MES\Filament\Resources\Shifts\Pages\ListShifts;
use Modules\MES\Filament\Resources\Shifts\Schemas\ShiftForm;
use Modules\MES\Filament\Resources\Shifts\Tables\ShiftsTable;
use Modules\MES\Models\Shift;
use Override;
use UnitEnum;

final class ShiftResource extends Resource
{
    #[Override]
    protected static ?string $model = Shift::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 70;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/shifts';
    }

    public static function form(Schema $schema): Schema
    {
        return ShiftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShiftsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'create' => CreateShift::route('/create'),
            'edit' => EditShift::route('/{record}/edit'),
        ];
    }
}
