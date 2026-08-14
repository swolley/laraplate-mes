<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class NonConformancesTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('productionOrder.number')
                        ->label('Order')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('item.name')
                        ->label('Item')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->sortable(),
                    TextColumn::make('disposition')
                        ->badge()
                        ->toggleable(),
                    TextColumn::make('quantity')
                        ->numeric(decimalPlaces: 4)
                        ->sortable(),
                ]);
            },
        );
    }
}
