<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Resources\MachineProfiles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class MachineProfileForm
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
                TextInput::make('vendor')
                    ->required()
                    ->maxLength(128),
                TextInput::make('model')
                    ->required()
                    ->maxLength(128),
                TextInput::make('version')
                    ->required()
                    ->maxLength(32),
                Textarea::make('definition')
                    ->rows(16)
                    ->rule('json')
                    ->required()
                    ->formatStateUsing(static fn (mixed $state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (is_string($state) ? $state : ''))
                    ->dehydrateStateUsing(static fn (mixed $state): mixed => is_string($state) ? json_decode($state, true) : $state),
            ]);
    }
}
