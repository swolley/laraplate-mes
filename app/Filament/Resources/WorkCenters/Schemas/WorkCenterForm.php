<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\WorkCenterType;

final class WorkCenterForm
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
                    ->maxLength(32),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(array_combine(WorkCenterType::values(), WorkCenterType::values()))
                    ->required(),
                TextInput::make('capacity_per_hour')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('capacity_uom')
                    ->required()
                    ->maxLength(16)
                    ->default('pcs'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
