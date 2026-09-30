<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Operations materialised from the order's routing snapshot.
 *
 * Read-only: the records are written by the MES services, never by hand in the backoffice.
 */
final class OperationsRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'operations';

    #[Override]
    public function isReadOnly(): bool
    {
        return true;
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('sequence')
                    ->sortable(),
                TextColumn::make('description'),
                TextColumn::make('workCenter.name')
                    ->label('Work center'),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('is_parallel')
                    ->boolean(),
                TextColumn::make('actual_start_at')
                    ->dateTime(),
                TextColumn::make('actual_end_at')
                    ->dateTime(),
                TextColumn::make('actual_minutes')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('efficiency')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%'),
            ]);
    }
}
