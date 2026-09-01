<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizQuestions\Schemas;

use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class QuizQuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')
                ->label('Въпрос')
                ->required()
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            TextInput::make('option_1')->label('Опция 1')->required()->maxLength(255),
            TextInput::make('option_2')->label('Опция 2')->required()->maxLength(255),
            TextInput::make('option_3')->label('Опция 3')->required()->maxLength(255),
            TextInput::make('option_4')->label('Опция 4')->required()->maxLength(255),

            Radio::make('correct_option')
                ->label('Верен отговор')
                ->options([1 => 'Опция 1', 2 => 'Опция 2', 3 => 'Опция 3', 4 => 'Опция 4'])
                ->required()
                ->default(1)
                ->inline(),

            Textarea::make('source_note')
                ->label('Бележка за проверка (от генератора)')
                ->helperText('Къде/как да се провери фактът. Не се показва на сайта.')
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('Активен')
                ->helperText('Генерираните въпроси минават двойна LLM проверка и влизат активни; деактивирай при съмнение.')
                ->default(true),
        ]);
    }
}
