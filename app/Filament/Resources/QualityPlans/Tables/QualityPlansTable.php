<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;

final class QualityPlansTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('item.name')
                        ->label('Item')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('routingOperation.description')
                        ->label('Operation')
                        ->placeholder('Final')
                        ->toggleable(),
                    TextColumn::make('version')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('valid_from')
                        ->date()
                        ->sortable(),
                    IconColumn::make('is_active')
                        ->boolean()
                        ->sortable(),
                ]);
            },
        );
    }
}
