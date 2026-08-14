<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Shifts\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class ShiftsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('start_time')
                        ->sortable(),
                    TextColumn::make('end_time')
                        ->sortable(),
                    IconColumn::make('is_active')
                        ->boolean()
                        ->sortable(),
                ]);
            },
        );
    }
}
