<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineMessages;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\MES\Filament\Resources\MachineMessages\Pages\ListMachineMessages;
use Modules\MES\Filament\Resources\MachineMessages\Pages\ViewMachineMessage;
use Modules\MES\Filament\Resources\MachineMessages\Tables\MachineMessagesTable;
use Modules\MES\Models\MachineMessage;
use Override;
use UnitEnum;

/**
 * Read-only: these records are written by the machine pipeline, never by hand.
 */
final class MachineMessageResource extends Resource
{
    #[Override]
    protected static ?string $model = MachineMessage::class;

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'MES - Machine connectivity';

    #[Override]
    protected static ?int $navigationSort = 50;

    #[Override]
    protected static ?string $recordTitleAttribute = 'message_id';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'mes/machine-messages';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MachineMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMachineMessages::route('/'),
            'view' => ViewMachineMessage::route('/{record}'),
        ];
    }
}
