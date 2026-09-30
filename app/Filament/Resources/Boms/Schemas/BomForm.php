<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Boms\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\ConsumptionMethod;

final class BomForm
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
                TextInput::make('version')
                    ->required()
                    ->maxLength(32),
                DatePicker::make('valid_from')
                    ->required(),
                DatePicker::make('valid_to'),
                Toggle::make('is_active')
                    ->default(true),
                Repeater::make('bomLines')
                    ->relationship()
                    ->label('Components')
                    ->schema([
                        Select::make('item_id')
                            ->relationship('item', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001),
                        TextInput::make('uom')
                            ->required()
                            ->maxLength(16)
                            ->default('pcs'),
                        Select::make('consumption_method')
                            ->options(array_combine(ConsumptionMethod::values(), ConsumptionMethod::values()))
                            ->required()
                            ->default(ConsumptionMethod::Backflush->value),
                        Select::make('routing_operation_id')
                            ->relationship('routingOperation', 'description')
                            ->label('Backflush on operation')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])
                    ->orderColumn('sort_order')
                    ->columns(5)
                    ->defaultItems(0)
                    ->addActionLabel('Add component')
                    ->columnSpanFull(),
            ]);
    }
}
