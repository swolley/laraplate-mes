<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\NonConformances\Pages\EditNonConformance;
use Modules\MES\Filament\Resources\NonConformances\Pages\ListNonConformances;
use Modules\MES\Filament\Resources\NonConformances\Schemas\NonConformanceForm;
use Modules\MES\Filament\Resources\NonConformances\Tables\NonConformancesTable;
use Modules\MES\Models\NonConformance;
use Override;
use UnitEnum;

/**
 * Non-conformances are opened by the quality flow and resolved/closed through
 * NonConformanceService (rework spawns a linked order), so this resource offers
 * list and edit only.
 */
final class NonConformanceResource extends Resource
{
    #[Override]
    protected static ?string $model = NonConformance::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 55;

    #[Override]
    protected static ?string $recordTitleAttribute = 'id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/non-conformances';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return NonConformanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NonConformancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNonConformances::route('/'),
            'edit' => EditNonConformance::route('/{record}/edit'),
        ];
    }
}
