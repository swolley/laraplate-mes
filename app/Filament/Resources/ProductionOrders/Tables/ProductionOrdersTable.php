<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class ProductionOrdersTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('number')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('item.name')
                        ->label('Item')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->sortable(),
                    TextColumn::make('quantity_planned')
                        ->numeric(decimalPlaces: 4)
                        ->sortable(),
                    TextColumn::make('quantity_produced')
                        ->numeric(decimalPlaces: 4)
                        ->sortable(),
                    TextColumn::make('planned_start_at')
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('warehouse.name')
                        ->label('Warehouse')
                        ->toggleable(),
                ]);
            },
        );
    }
}
