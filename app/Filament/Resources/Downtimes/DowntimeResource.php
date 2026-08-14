<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\Downtimes\Pages\CreateDowntime;
use Modules\MES\Filament\Resources\Downtimes\Pages\EditDowntime;
use Modules\MES\Filament\Resources\Downtimes\Pages\ListDowntimes;
use Modules\MES\Filament\Resources\Downtimes\Schemas\DowntimeForm;
use Modules\MES\Filament\Resources\Downtimes\Tables\DowntimesTable;
use Modules\MES\Models\Downtime;
use Override;
use UnitEnum;

final class DowntimeResource extends Resource
{
    #[Override]
    protected static ?string $model = Downtime::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 60;

    #[Override]
    protected static ?string $recordTitleAttribute = 'id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/downtimes';
    }

    public static function form(Schema $schema): Schema
    {
        return DowntimeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DowntimesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDowntimes::route('/'),
            'create' => CreateDowntime::route('/create'),
            'edit' => EditDowntime::route('/{record}/edit'),
        ];
    }
}
