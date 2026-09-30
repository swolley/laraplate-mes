<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Materials consumed by the order, by backflush or manual record.
 *
 * Read-only: the records are written by the MES services, never by hand in the backoffice.
 */
final class MaterialConsumptionsRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'materialConsumptions';

    #[Override]
    public function isReadOnly(): bool
    {
        return true;
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('recorded_at')
            ->columns([
                TextColumn::make('item.name')
                    ->label('Item'),
                TextColumn::make('quantity_planned')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('quantity_consumed')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('variance')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('uom'),
                IconColumn::make('is_backflush')
                    ->boolean(),
                IconColumn::make('stock_shortage')
                    ->boolean(),
                TextColumn::make('recorded_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
