<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\QualityPlans\Pages\CreateQualityPlan;
use Modules\MES\Filament\Resources\QualityPlans\Pages\EditQualityPlan;
use Modules\MES\Filament\Resources\QualityPlans\Pages\ListQualityPlans;
use Modules\MES\Filament\Resources\QualityPlans\Schemas\QualityPlanForm;
use Modules\MES\Filament\Resources\QualityPlans\Tables\QualityPlansTable;
use Modules\MES\Models\QualityPlan;
use Override;
use UnitEnum;

final class QualityPlanResource extends Resource
{
    #[Override]
    protected static ?string $model = QualityPlan::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES';

    #[Override]
    protected static ?int $navigationSort = 55;

    #[Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/quality-plans';
    }

    public static function form(Schema $schema): Schema
    {
        return QualityPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQualityPlans::route('/'),
            'create' => CreateQualityPlan::route('/create'),
            'edit' => EditQualityPlan::route('/{record}/edit'),
        ];
    }
}
