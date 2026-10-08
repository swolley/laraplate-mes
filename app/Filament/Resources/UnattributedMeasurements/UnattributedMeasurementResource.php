<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\UnattributedMeasurements;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\UnattributedMeasurements\Pages\ListUnattributedMeasurements;
use Modules\MES\Filament\Resources\UnattributedMeasurements\Tables\UnattributedMeasurementsTable;
use Modules\MES\Models\UnattributedMeasurement;
use Override;
use UnitEnum;

/**
 * Read-only: the machine pipeline writes these rows; a person only assigns them to a quality check.
 */
final class UnattributedMeasurementResource extends Resource
{
    #[Override]
    protected static ?string $model = UnattributedMeasurement::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 45;

    #[Override]
    protected static ?string $recordTitleAttribute = 'id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/unattributed-measurements';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return UnattributedMeasurementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnattributedMeasurements::route('/'),
        ];
    }
}
