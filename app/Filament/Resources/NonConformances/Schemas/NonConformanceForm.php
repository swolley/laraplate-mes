<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\NonConformances\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\NonConformanceDisposition;
use Modules\MES\Enums\NonConformanceStatus;

final class NonConformanceForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                Select::make('status')
                    ->options(array_combine(NonConformanceStatus::values(), NonConformanceStatus::values()))
                    ->required(),
                Select::make('disposition')
                    ->options(array_combine(NonConformanceDisposition::values(), NonConformanceDisposition::values())),
                TextInput::make('quantity')
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('resolved_at'),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
