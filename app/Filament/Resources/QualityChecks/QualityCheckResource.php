<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\QualityChecks\Pages\CreateQualityCheck;
use Modules\MES\Filament\Resources\QualityChecks\Pages\EditQualityCheck;
use Modules\MES\Filament\Resources\QualityChecks\Pages\ListQualityChecks;
use Modules\MES\Filament\Resources\QualityChecks\Schemas\QualityCheckForm;
use Modules\MES\Filament\Resources\QualityChecks\Tables\QualityChecksTable;
use Modules\MES\Models\QualityCheck;
use Override;
use UnitEnum;

final class QualityCheckResource extends Resource
{
    #[Override]
    protected static ?string $model = QualityCheck::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 50;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/quality-checks';
    }

    public static function form(Schema $schema): Schema
    {
        return QualityCheckForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityChecksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityChecks::route('/'),
            'create' => CreateQualityCheck::route('/create'),
            'edit' => EditQualityCheck::route('/{record}/edit'),
        ];
    }
}
