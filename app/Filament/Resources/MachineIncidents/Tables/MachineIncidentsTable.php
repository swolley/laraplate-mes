<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineIncidents\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\MES\Enums\MachineIncidentType;

final class MachineIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('source.name')->label('Source'),
                TextColumn::make('device.external_id')->label('Device'),
                TextColumn::make('detail')
                    ->formatStateUsing(static fn (mixed $state): string => is_array($state) ? mb_strimwidth((string) json_encode($state), 0, 80, '...') : '')
                    ->toggleable(),
                TextColumn::make('resolved_at')->dateTime()->placeholder('open'),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(MachineIncidentType::values(), MachineIncidentType::values())),
                Filter::make('unresolved')
                    ->label('Open only')
                    ->query(static fn (Builder $query): Builder => $query->whereNull('resolved_at')),
            ]);
    }
}
