<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineIncidents;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\MachineIncidents\Pages\ListMachineIncidents;

use Modules\MES\Filament\Resources\MachineIncidents\Tables\MachineIncidentsTable;
use Modules\MES\Models\MachineIncident;
use Override;
use UnitEnum;

/**
 * Read-only: these records are written by the machine pipeline, never by hand.
 */
final class MachineIncidentResource extends Resource
{
    #[Override]
    protected static ?string $model = MachineIncident::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 60;

    #[Override]
    protected static ?string $recordTitleAttribute = 'type';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/machine-incidents';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MachineIncidentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineIncidents::route('/'),
        ];
    }
}
