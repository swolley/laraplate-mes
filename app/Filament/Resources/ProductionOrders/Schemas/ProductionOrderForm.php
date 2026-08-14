<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\ProductionOrders\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class ProductionOrderForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                TextInput::make('number')
                    ->disabled(),
                DateTimePicker::make('planned_start_at')
                    ->required(),
                DateTimePicker::make('planned_end_at')
                    ->required(),
                TextInput::make('quantity_produced')
                    ->numeric(),
                TextInput::make('quantity_scrapped')
                    ->numeric(),
            ]);
    }
}
