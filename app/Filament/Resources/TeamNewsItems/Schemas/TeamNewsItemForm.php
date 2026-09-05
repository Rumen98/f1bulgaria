<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsItems\Schemas;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class TeamNewsItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Оригинал (disabled) | редактируем български превод.
            Grid::make(2)->schema([
                TextInput::make('title_original')
                    ->label('Оригинално заглавие')
                    ->disabled(),
                TextInput::make('title_bg')
                    ->label('Заглавие (BG)')
                    ->maxLength(120),
            ]),

            Grid::make(2)->schema([
                Textarea::make('content_snippet')
                    ->label('Оригинален откъс')
                    ->rows(5)
                    ->disabled(),
                Textarea::make('summary_bg')
                    ->label('Резюме (BG)')
                    ->rows(5)
                    ->maxLength(400),
            ]),

            Grid::make(2)->schema([
                Toggle::make('is_tsolov')
                    ->label('Новина за Никола Цолов')
                    // Автоматично се вдига само когато името е в заглавието.
                    // Ключът е за статиите, където той е темата, но името го
                    // няма („Red Bull junior completes first F1 test").
                    ->helperText('Показва се в неговия кът. Вдига се автоматично, ако името е в заглавието.'),
                Toggle::make('is_f1_related')
                    ->label('Новина от Формула 1')
                    ->helperText('Изключено = само в кътa на Цолов, извън главната емисия.'),
            ]),

            Grid::make(3)->schema([
                Select::make('constructor_id')
                    ->label('Отбор')
                    ->relationship('constructor', 'name')
                    ->searchable()
                    ->placeholder('Глобална / без отбор'),
                TextInput::make('importance_score')
                    ->label('Важност (1-5)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(5),
                Select::make('status')
                    ->label('Статус')
                    ->options(self::statusOptions())
                    ->required()
                    // Pipeline-ът публикува обогатените „Чакаща" автоматично —
                    // временното връщане в „Чакаща" не е траен начин за скриване.
                    ->helperText('„Чакаща" се публикува автоматично при следващия дневен цикъл. За трайно сваляне от сайта използвай „Отхвърлена".'),
            ]),

            // Генерирани полета — само за справка (disabled).
            Grid::make(3)->schema([
                Select::make('classification')
                    ->label('Класификация')
                    ->options(self::classificationOptions())
                    ->disabled(),
                Select::make('source_id')
                    ->label('Източник')
                    ->relationship('source', 'name')
                    ->disabled(),
                DateTimePicker::make('published_at')
                    ->label('Публикувано')
                    ->timezone('Europe/Sofia')
                    ->disabled(),
            ]),

            TextInput::make('external_url')
                ->label('Оригинален URL')
                ->disabled()
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(NewsStatus::cases())
            ->mapWithKeys(fn (NewsStatus $s) => [$s->value => $s->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function classificationOptions(): array
    {
        return collect(NewsClassification::cases())
            ->mapWithKeys(fn (NewsClassification $c) => [$c->value => $c->label()])
            ->all();
    }
}
