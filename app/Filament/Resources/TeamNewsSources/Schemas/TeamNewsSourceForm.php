<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsSources\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TeamNewsSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Име')
                ->required()
                ->maxLength(255),
            TextInput::make('feed_url')
                ->label('Feed URL')
                ->url()
                ->required()
                ->maxLength(255),
            Select::make('constructor_id')
                ->label('Отбор')
                ->relationship('constructor', 'name')
                ->searchable()
                ->placeholder('Глобален (за всички отбори)'),
            Select::make('type')
                ->label('Тип')
                ->options([
                    'rss' => 'RSS',
                    'atom' => 'Atom',
                    'youtube_rss' => 'YouTube RSS',
                ])
                ->default('rss')
                ->required(),
            Select::make('language')
                ->label('Език')
                ->options([
                    'en' => 'English',
                    'bg' => 'Български',
                    'it' => 'Italiano',
                    'de' => 'Deutsch',
                ])
                ->default('en')
                ->required(),
            Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
        ]);
    }
}
