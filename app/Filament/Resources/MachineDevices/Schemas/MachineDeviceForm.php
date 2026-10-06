<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineDevices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class MachineDeviceForm
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
                Select::make('source_id')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('external_id')
                    ->required()
                    ->maxLength(128),
                Select::make('work_center_id')
                    ->relationship('workCenter', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('machine_profile_id')
                    ->relationship('profile', 'model')
                    ->searchable()
                    ->preload(),
                TextInput::make('profile_version')
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
