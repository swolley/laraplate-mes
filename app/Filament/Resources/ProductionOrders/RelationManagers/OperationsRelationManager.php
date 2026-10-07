<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Services\MachineCountAssigner;
use Modules\MES\Services\OperationQuantityDeclarer;
use Override;

/**
 * Operations materialised from the order's routing snapshot.
 *
 * Read-only: the records are written by the MES services, never by hand in the backoffice.
 */
final class OperationsRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'operations';

    #[Override]
    public function isReadOnly(): bool
    {
        return true;
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('sequence')
                    ->sortable(),
                TextColumn::make('description'),
                TextColumn::make('workCenter.name')
                    ->label('Work center'),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('is_parallel')
                    ->boolean(),
                TextColumn::make('actual_start_at')
                    ->dateTime(),
                TextColumn::make('actual_end_at')
                    ->dateTime(),
                TextColumn::make('actual_minutes')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('efficiency')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%'),
                TextColumn::make('machine_good_quantity')
                    ->label('Machine good')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('machine_scrap_quantity')
                    ->label('Machine scrap')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('declared_good_quantity')
                    ->label('Declared good')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-'),
                TextColumn::make('declared_scrap_quantity')
                    ->label('Declared scrap')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('-'),
                TextColumn::make('target_reached_at')
                    ->label('Target reached')
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('declare')
                    ->label('Declare quantities')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(static fn (ProductionOrderOperation $record): array => [
                        'declared_good_quantity' => $record->declared_good_quantity ?? $record->machine_good_quantity,
                        'declared_scrap_quantity' => $record->declared_scrap_quantity ?? $record->machine_scrap_quantity,
                    ])
                    ->schema([
                        TextInput::make('declared_good_quantity')->numeric()->minValue(0)->required(),
                        TextInput::make('declared_scrap_quantity')->numeric()->minValue(0)->required(),
                    ])
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(static function (ProductionOrderOperation $record, array $data): void {
                        resolve(OperationQuantityDeclarer::class)->declare($record, (float) $data['declared_good_quantity'], (float) $data['declared_scrap_quantity'], is_numeric(auth()->id()) ? (int) auth()->id() : null);
                    }),
                Action::make('assign_counts')
                    ->label('Assign machine counts')
                    ->icon(Heroicon::OutlinedLink)
                    ->schema([
                        DateTimePicker::make('from')->seconds(false)->required(),
                        DateTimePicker::make('to')->seconds(false)->required()->after('from'),
                    ])
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(static function (ProductionOrderOperation $record, array $data): void {
                        $assigned = resolve(MachineCountAssigner::class)->assign($record->id, Carbon::parse($data['from']), Carbon::parse($data['to']));

                        Notification::make()->title("{$assigned} machine count rows assigned")->success()->send();
                    }),
            ]);
    }
}
