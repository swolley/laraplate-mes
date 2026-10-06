<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineMessages\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Modules\MES\Filament\Resources\MachineMessages\MachineMessageResource;
use Override;

final class ViewMachineMessage extends ViewRecord
{
    #[Override]
    protected static string $resource = MachineMessageResource::class;

    #[Override]
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('message_id'),
            TextEntry::make('source.name')->label('Source'),
            TextEntry::make('status')->badge(),
            TextEntry::make('received_at')->dateTime(),
            TextEntry::make('attempts'),
            TextEntry::make('error')->placeholder('-'),
            TextEntry::make('payload')
                ->limit(5000)
                ->fontFamily('mono')
                ->columnSpanFull(),
        ]);
    }
}
