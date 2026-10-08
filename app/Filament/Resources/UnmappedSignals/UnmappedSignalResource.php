<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\UnmappedSignals;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\UnmappedSignals\Pages\ListUnmappedSignals;

use Modules\MES\Filament\Resources\UnmappedSignals\Tables\UnmappedSignalsTable;
use Modules\MES\Models\UnmappedSignal;
use Override;
use UnitEnum;

/**
 * Read-only: these records are written by the machine pipeline, never by hand.
 */
final class UnmappedSignalResource extends Resource
{
    #[Override]
    protected static ?string $model = UnmappedSignal::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 40;

    #[Override]
    protected static ?string $recordTitleAttribute = 'signal_key';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/unmapped-signals';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return UnmappedSignalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnmappedSignals::route('/'),
        ];
    }
}
