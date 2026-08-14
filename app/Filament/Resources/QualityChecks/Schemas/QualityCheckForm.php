<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\QualityChecks\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\QualityCheckStatus;

final class QualityCheckForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                Select::make('production_order_id')
                    ->relationship('productionOrder', 'number')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('item_id')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('status')
                    ->options(array_combine(QualityCheckStatus::values(), QualityCheckStatus::values()))
                    ->default(QualityCheckStatus::Pending->value)
                    ->required(),
                DateTimePicker::make('checked_at'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
