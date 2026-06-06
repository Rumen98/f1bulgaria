<?php

declare(strict_types=1);

namespace App\Filament\Resources\F2Seasons\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class F2SeasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('year')->label('Година')->numeric()->required()->minValue(1950)->maxValue(2100),
            Toggle::make('is_current')->label('Текущ сезон'),
        ]);
    }
}
