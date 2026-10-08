<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\OperationProcessSummaries;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\OperationProcessSummaries\Pages\ListOperationProcessSummaries;
use Modules\MES\Filament\Resources\OperationProcessSummaries\Tables\OperationProcessSummariesTable;
use Modules\MES\Models\OperationProcessSummary;
use Override;
use UnitEnum;

/**
 * Read-only: the summaries are written when an operation completes, never by hand.
 */
final class OperationProcessSummaryResource extends Resource
{
    #[Override]
    protected static ?string $model = OperationProcessSummary::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 50;

    #[Override]
    protected static ?string $recordTitleAttribute = 'id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/operation-process-summaries';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return OperationProcessSummariesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationProcessSummaries::route('/'),
        ];
    }
}
