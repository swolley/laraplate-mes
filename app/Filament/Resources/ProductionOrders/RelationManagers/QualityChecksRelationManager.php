<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Quality checks raised for the order, in-process and final.
 *
 * Read-only: the records are written by the MES services, never by hand in the backoffice.
 */
final class QualityChecksRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'qualityChecks';

    #[Override]
    public function isReadOnly(): bool
    {
        return true;
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('operation.description')
                    ->label('Operation')
                    ->placeholder('Final'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('checked_at')
                    ->dateTime(),
            ]);
    }
}
