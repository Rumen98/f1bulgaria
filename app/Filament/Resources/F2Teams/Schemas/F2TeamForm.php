<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Teams\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class F2TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('f2_season_id')->label('Сезон')->relationship('season', 'year')->required(),
            TextInput::make('name')->label('Име')->required(),
            TextInput::make('slug')->label('Slug')->required(),
            ColorPicker::make('color_hex')->label('Цвят'),
        ]);
    }
}
