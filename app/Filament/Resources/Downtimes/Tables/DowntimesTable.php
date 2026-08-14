<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class DowntimesTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('workCenter.name')
                        ->label('Work center')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('cause')
                        ->badge()
                        ->sortable(),
                    TextColumn::make('started_at')
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('ended_at')
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('duration_minutes')
                        ->numeric(decimalPlaces: 2)
                        ->sortable(),
                ]);
            },
        );
    }
}
