<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class WorkCentersTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('code')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('type')
                        ->badge()
                        ->sortable(),
                    TextColumn::make('capacity_per_hour')
                        ->numeric(decimalPlaces: 4)
                        ->sortable(),
                    TextColumn::make('capacity_uom')
                        ->toggleable(),
                    IconColumn::make('is_active')
                        ->boolean()
                        ->sortable(),
                ]);
            },
        );
    }
}
