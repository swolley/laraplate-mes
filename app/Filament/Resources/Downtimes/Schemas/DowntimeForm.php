<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\Downtimes\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\MES\Enums\DowntimeCause;

final class DowntimeForm
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
                Select::make('work_center_id')
                    ->relationship('workCenter', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('cause')
                    ->options(array_combine(DowntimeCause::values(), DowntimeCause::values()))
                    ->required(),
                DateTimePicker::make('started_at')
                    ->required(),
                DateTimePicker::make('ended_at'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
