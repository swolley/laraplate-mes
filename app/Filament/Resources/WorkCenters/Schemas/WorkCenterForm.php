<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\WorkCenters\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\WorkCenterType;

final class WorkCenterForm
{
    use HasForm;

    /**
     * Calendar days as stored in `mes_work_center_calendars.day_of_week` (0 = Monday).
     *
     * @var array<int, string>
     */
    private const array DAYS_OF_WEEK = [
        0 => 'Monday',
        1 => 'Tuesday',
        2 => 'Wednesday',
        3 => 'Thursday',
        4 => 'Friday',
        5 => 'Saturday',
        6 => 'Sunday',
    ];

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
                Repeater::make('calendar')
                    ->relationship()
                    ->label('Working calendar')
                    ->schema([
                        Select::make('day_of_week')
                            ->options(self::DAYS_OF_WEEK)
                            ->required(),
                        TimePicker::make('start_time')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('end_time')
                            ->seconds(false)
                            ->required()
                            ->after('start_time'),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add slot')
                    ->columnSpanFull(),
            ]);
    }
}
