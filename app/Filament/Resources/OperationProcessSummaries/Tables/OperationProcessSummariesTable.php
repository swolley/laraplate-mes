<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\OperationProcessSummaries\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\MES\Models\MachineSignal;

final class OperationProcessSummariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn ($query) => $query->with(['operation.productionOrder', 'signal']))
            ->defaultSort('last_ts', 'desc')
            ->columns([
                TextColumn::make('operation.productionOrder.number')->label('Order')->searchable()->sortable(),
                TextColumn::make('operation.description')->label('Operation'),
                TextColumn::make('signal.key')->label('Signal')->searchable()->sortable(),
                TextColumn::make('min')->numeric(decimalPlaces: 3),
                TextColumn::make('max')->numeric(decimalPlaces: 3),
                TextColumn::make('avg')->numeric(decimalPlaces: 3),
                TextColumn::make('count')->numeric()->sortable(),
                TextColumn::make('out_of_range_count')->label('Out of range')->numeric()->sortable(),
                TextColumn::make('first_ts')->label('From')->dateTime()->sortable(),
                TextColumn::make('last_ts')->label('To')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('signal_id')
                    ->label('Signal')
                    ->options(static fn (): array => MachineSignal::query()->where('role', 'process_value')->orderBy('key')->get()->pluck('key', 'id')->all()),
            ]);
    }
}
