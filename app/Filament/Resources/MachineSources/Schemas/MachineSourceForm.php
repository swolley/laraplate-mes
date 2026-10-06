<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineSources\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Machine\Normalizers\NormalizerRegistry;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class MachineSourceForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('code')
                    ->required()
                    ->maxLength(64),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('normalizer')
                    ->options(static fn (): array => array_combine(resolve(NormalizerRegistry::class)->keys(), resolve(NormalizerRegistry::class)->keys()))
                    ->default('canonical')
                    ->required(),
                Select::make('transport')
                    ->options(array_combine(MachineTransport::values(), MachineTransport::values()))
                    ->default(MachineTransport::Http->value)
                    ->live()
                    ->required(),
                TextInput::make('mqtt_topic')
                    ->maxLength(255)
                    ->visible(static fn ($get): bool => $get('transport') === MachineTransport::Mqtt->value),
                KeyValue::make('normalizer_options')
                    ->helperText('Paths for the mapped_json normaliser (device, signal, timestamp, value, ...).'),
                TextInput::make('heartbeat_timeout_seconds')
                    ->numeric()
                    ->minValue(1)
                    ->default(120)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
