<?php

declare(strict_types=1);

namespace App\Filament\Resources\DriverCanonicals\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DriverCanonicalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Идентичност')
                    ->description('Изгражда се автоматично от синхрона — само за справка.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')->label('Име')->disabled(),
                        TextInput::make('last_name')->label('Фамилия')->disabled(),
                        TextInput::make('slug')->label('Slug')->disabled(),
                        TextInput::make('code')->label('Код')->disabled(),
                    ]),

                Section::make('Ръчно редактируеми')
                    ->description('Тези полета се поддържат ръчно и допълват автоматичните данни.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('photo_url')->label('URL на снимка')->url()->maxLength(2048),
                        TextInput::make('country_code')->label('Държава (ISO3)')->maxLength(3),
                        TextInput::make('permanent_number')->label('Постоянен номер')->numeric(),
                        Toggle::make('is_active')->label('Активен пилот'),
                        Textarea::make('bio_bg')->label('Биография (BG)')->rows(5)->columnSpanFull(),
                    ]),

                Section::make('Изчислена статистика')
                    ->description('Изчислява се от drivers:backfill-canonical — не се редактира тук.')
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
