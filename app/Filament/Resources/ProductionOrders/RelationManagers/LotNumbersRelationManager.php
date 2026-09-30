<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Lots produced by the order.
 *
 * Read-only: the records are written by the MES services, never by hand in the backoffice.
 */
final class LotNumbersRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'lotNumbers';

    #[Override]
    public function isReadOnly(): bool
    {
        return true;
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->defaultSort('produced_at')
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('item.name')
                    ->label('Item'),
                TextColumn::make('quantity')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('produced_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
