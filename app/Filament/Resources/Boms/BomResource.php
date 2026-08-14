<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\Boms\Pages\CreateBom;
use Modules\MES\Filament\Resources\Boms\Pages\EditBom;
use Modules\MES\Filament\Resources\Boms\Pages\ListBoms;
use Modules\MES\Filament\Resources\Boms\Schemas\BomForm;
use Modules\MES\Filament\Resources\Boms\Tables\BomsTable;
use Modules\MES\Models\Bom;
use Override;
use UnitEnum;

final class BomResource extends Resource
{
    #[Override]
    protected static ?string $model = Bom::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 20;

    #[Override]
    protected static ?string $recordTitleAttribute = 'version';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/boms';
    }

    public static function form(Schema $schema): Schema
    {
        return BomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBoms::route('/'),
            'create' => CreateBom::route('/create'),
            'edit' => EditBom::route('/{record}/edit'),
        ];
    }
}
