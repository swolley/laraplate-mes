<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityPlans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class QualityPlanForm
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
                Select::make('item_id')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('routing_operation_id')
                    ->relationship('routingOperation', 'description')
                    ->searchable()
                    ->preload()
                    ->helperText('Leave empty for a final inspection on the finished item.'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('version')
                    ->required()
                    ->maxLength(32),
                DatePicker::make('valid_from')
                    ->required(),
                DatePicker::make('valid_to'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
