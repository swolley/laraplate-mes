<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\WorkCenters\Pages\CreateWorkCenter;
use Modules\MES\Filament\Resources\WorkCenters\Pages\EditWorkCenter;
use Modules\MES\Filament\Resources\WorkCenters\Pages\ListWorkCenters;
use Modules\MES\Filament\Resources\WorkCenters\Schemas\WorkCenterForm;
use Modules\MES\Filament\Resources\WorkCenters\Tables\WorkCentersTable;
use Modules\MES\Models\WorkCenter;
use Override;
use UnitEnum;

final class WorkCenterResource extends Resource
{
    #[Override]
    protected static ?string $model = WorkCenter::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 10;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/work-centers';
    }

    public static function form(Schema $schema): Schema
    {
        return WorkCenterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkCentersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkCenters::route('/'),
            'create' => CreateWorkCenter::route('/create'),
            'edit' => EditWorkCenter::route('/{record}/edit'),
        ];
    }
}
