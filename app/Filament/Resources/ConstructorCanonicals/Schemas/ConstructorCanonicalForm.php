<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConstructorCanonicals\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConstructorCanonicalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Идентичност')
                    ->description('Изгражда се автоматично от синхрона — само за справка.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Име')->disabled(),
                        TextInput::make('slug')->label('Slug')->disabled(),
                    ]),

                Section::make('Ръчно редактируеми')
                    ->description('Тези полета се поддържат ръчно и допълват автоматичните данни.')
                    ->columns(2)
                    ->schema([
                        ColorPicker::make('color_hex')->label('Цвят'),
                        TextInput::make('logo_url')->label('URL на лого')->url()->maxLength(2048),
                        Toggle::make('is_active')->label('Активен отбор'),
                        Textarea::make('bio_bg')->label('Описание (BG)')->rows(5)->columnSpanFull(),
                    ]),

                Section::make('Изчислена статистика')
                    ->description('Изчислява се от constructors:backfill-canonical — не се редактира тук.')
                    ->columns(4)
                    ->schema([
                        TextInput::make('total_wins')->label('Победи')->disabled(),
                        TextInput::make('total_podiums')->label('Подиуми')->disabled(),
                        TextInput::make('total_poles')->label('Pole')->disabled(),
                        TextInput::make('total_races')->label('Състезания')->disabled(),
                    ]),
            ]);
    }
}
