<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;
use Modules\MES\Data\WorkCenterKpis;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Services\WorkCenterKpiStore;

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
                    TextColumn::make('oee_today')
                        ->label('OEE (today)')
                        ->state(static fn (WorkCenter $record): ?string => ($kpis = resolve(WorkCenterKpiStore::class)->get($record->id, now())) instanceof WorkCenterKpis
                            ? number_format($kpis->oee * 100, 1) . '%' . ($kpis->incomplete_data ? ' (incomplete)' : '')
                            : null)
                        ->placeholder('-')
                        ->toggleable(),
                ]);
            },
        );
    }
}
