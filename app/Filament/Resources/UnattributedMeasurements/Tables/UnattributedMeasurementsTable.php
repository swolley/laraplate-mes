<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\UnattributedMeasurements\Tables;

use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\QualityCheck;
use Modules\MES\Models\QualityPlanCharacteristic;
use Modules\MES\Models\UnattributedMeasurement;
use Modules\MES\Services\UnattributedMeasurementAssigner;

final class UnattributedMeasurementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn ($query) => $query->with(['signal.characteristic', 'signal.device.workCenter']))
            ->defaultSort('ts', 'desc')
            ->columns([
                TextColumn::make('signal.key')->label('Signal')->searchable()->sortable(),
                TextColumn::make('signal.characteristic.characteristic')->label('Characteristic'),
                TextColumn::make('workCenterName')->label('Work center')->state(static fn (UnattributedMeasurement $record): ?string => $record->signal?->device?->workCenter?->name),
                TextColumn::make('production_order_operation_id')->label('Operation')->placeholder('-')->sortable(),
                TextColumn::make('value')->numeric(decimalPlaces: 4),
                TextColumn::make('serial')->placeholder('-')->searchable(),
                TextColumn::make('ts')->dateTime()->sortable(),
                TextColumn::make('assigned_at')->dateTime()->placeholder('-')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('assigned')
                    ->label('Assigned')
                    ->queries(
                        true: static fn ($query) => $query->whereNotNull('assigned_at'),
                        false: static fn ($query) => $query->whereNull('assigned_at'),
                    ),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Assign')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(static fn (UnattributedMeasurement $record): bool => $record->assigned_at === null && $record->signal?->quality_plan_characteristic_id !== null && self::canAssign())
                    ->schema([
                        Select::make('quality_check_id')
                            ->label('Quality check')
                            ->options(static fn (UnattributedMeasurement $record): array => self::checkOptions($record))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(static function (UnattributedMeasurement $record, array $data): void {
                        $check = QualityCheck::query()->whereKey($data['quality_check_id'])->firstOrFail();

                        try {
                            resolve(UnattributedMeasurementAssigner::class)->assign($record, $check);
                        } catch (DomainException $domain_exception) {
                            Notification::make()->title($domain_exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Measurement assigned')->success()->send();
                    }),
            ]);
    }

    /**
     * The checks that can take the measurement: same company, and a plan that holds the characteristic of its signal.
     *
     * @return array<int, string>
     */
    private static function checkOptions(UnattributedMeasurement $record): array
    {
        $characteristic_id = $record->signal?->quality_plan_characteristic_id;

        if ($characteristic_id === null) {
            return [];
        }

        return QualityCheck::query()
            ->where('company_id', $record->company_id)
            ->whereIn('quality_plan_id', QualityPlanCharacteristic::query()->whereKey($characteristic_id)->select('quality_plan_id'))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(static fn (QualityCheck $check): array => [$check->id => "#{$check->id} {$check->name} ({$check->status->value})"])
            ->all();
    }

    private static function canAssign(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->isSuperAdmin() || $user->hasPermissionTo(PermissionName::forClass(MachineSignal::class, 'insert')));
    }
}
