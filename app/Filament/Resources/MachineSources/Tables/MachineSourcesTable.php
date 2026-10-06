<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class MachineSourcesTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('code')->searchable()->sortable(),
                    TextColumn::make('name')->searchable()->sortable(),
                    TextColumn::make('transport')->badge(),
                    TextColumn::make('normalizer'),
                    TextColumn::make('last_seen_at')->dateTime()->sortable(),
                    IconColumn::make('is_active')->boolean(),
                ]);
            },
        );
    }
}
