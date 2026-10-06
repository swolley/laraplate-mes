<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\MES\Enums\SignalRole;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\QualityPlanCharacteristic;
use Override;

/**
 * The signals of a device: their role and the per-role configuration (maps, counter modes, ranges).
 */
final class SignalsRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'signals';

    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->required()
                ->maxLength(128),
            Select::make('role')
                ->options(array_combine(SignalRole::values(), SignalRole::values()))
                ->required(),
            Select::make('data_type')
                ->options(['number' => 'number', 'boolean' => 'boolean', 'string' => 'string'])
                ->default('number')
                ->required(),
            TextInput::make('unit')
                ->maxLength(16),
            Textarea::make('config')
                ->rows(6)
                ->helperText('JSON, by role: {"map": {...}} for state and alarm, {"mode": "cumulative", "rollover_max": 65535} for counts, {"min": 0, "max": 100} for process values.')
                ->rule('json')
                ->formatStateUsing(static fn (mixed $state): ?string => is_array($state) ? (string) json_encode($state, JSON_UNESCAPED_SLASHES) : (is_string($state) ? $state : null))
                ->dehydrateStateUsing(static fn (mixed $state): mixed => is_string($state) && $state !== '' ? json_decode($state, true) : null),
            Select::make('quality_plan_characteristic_id')
                ->label('Quality characteristic (measurements)')
                ->options(static fn (): array => QualityPlanCharacteristic::query()->get()->pluck('characteristic', 'id')->all())
                ->searchable(),
        ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                TextColumn::make('key')->searchable()->sortable(),
                TextColumn::make('role')->badge(),
                TextColumn::make('data_type'),
                TextColumn::make('unit'),
                TextColumn::make('config')
                    ->formatStateUsing(static fn (mixed $state): string => is_array($state) ? mb_strimwidth((string) json_encode($state), 0, 60, '...') : '')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        /** @var MachineDevice $device */
                        $device = $this->getOwnerRecord();

                        return [...$data, 'company_id' => $device->company_id];
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
