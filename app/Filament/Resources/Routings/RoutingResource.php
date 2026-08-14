<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Routings;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\Routings\Pages\CreateRouting;
use Modules\MES\Filament\Resources\Routings\Pages\EditRouting;
use Modules\MES\Filament\Resources\Routings\Pages\ListRoutings;
use Modules\MES\Filament\Resources\Routings\Schemas\RoutingForm;
use Modules\MES\Filament\Resources\Routings\Tables\RoutingsTable;
use Modules\MES\Models\Routing;
use Override;
use UnitEnum;

final class RoutingResource extends Resource
{
    #[Override]
    protected static ?string $model = Routing::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 30;

    #[Override]
    protected static ?string $recordTitleAttribute = 'version';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/routings';
    }

    public static function form(Schema $schema): Schema
    {
        return RoutingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoutingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoutings::route('/'),
            'create' => CreateRouting::route('/create'),
            'edit' => EditRouting::route('/{record}/edit'),
        ];
    }
}
