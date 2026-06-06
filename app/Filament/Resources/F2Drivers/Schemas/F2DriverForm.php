<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Drivers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class F2DriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('f2_season_id')->label('Сезон')->relationship('season', 'year')->required()->live(),
            Select::make('f2_team_id')->label('Отбор')
                ->relationship('team', 'name')
                ->searchable(),
            TextInput::make('first_name')->label('Име')->required(),
            TextInput::make('last_name')->label('Фамилия')->required(),
            TextInput::make('slug')->label('Slug')->required(),
            TextInput::make('country_code')->label('Държава (ISO3)')->maxLength(3),
            TextInput::make('position')->label('Позиция в класирането')->numeric()->minValue(1),
            TextInput::make('points')->label('Точки')->numeric()->default(0),
            Toggle::make('is_champion')->label('Шампион'),
        ]);
    }
}
