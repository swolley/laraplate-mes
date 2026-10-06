<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineMessages\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Core\Models\User;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Machine\MachineMessageReprocessor;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Policies\MesModelPolicy;

final class MachineMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('source.name')->label('Source')->sortable(),
                TextColumn::make('message_id')->searchable(),
                TextColumn::make('source_seq')->numeric(),
                TextColumn::make('transport')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('error')->limit(60)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(MachineMessageStatus::values(), MachineMessageStatus::values())),
                SelectFilter::make('source_id')->label('Source')->options(static fn (): array => MachineSource::query()->get()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('reprocess')
                    ->label('Reprocess')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(static function (MachineMessage $record): bool {
                        $user = auth()->user();

                        return $user instanceof User && resolve(MesModelPolicy::class)->reprocess($user, $record);
                    })
                    ->action(static function (MachineMessage $record): void {
                        resolve(MachineMessageReprocessor::class)->reprocess($record);

                        Notification::make()->title('Message queued for reprocessing')->success()->send();
                    }),
            ]);
    }
}
