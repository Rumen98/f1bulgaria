<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RaceResource\Pages;
use App\Models\Race;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RaceResource extends Resource
{
    protected static ?string $model = Race::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Състезания';

    protected static ?string $modelLabel = 'състезание';

    protected static ?string $pluralModelLabel = 'състезания';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('season_id')
                ->label('Сезон')
                ->relationship('season', 'year')
                ->required(),
            Forms\Components\TextInput::make('round')->label('Кръг')->numeric()->required(),
            Forms\Components\TextInput::make('name')->label('Име')->required(),
            Forms\Components\TextInput::make('circuit')->label('Писта')->required(),
            Forms\Components\TextInput::make('country')->label('Държава')->required(),
            Forms\Components\DateTimePicker::make('race_datetime_utc')->label('Старт (UTC)'),
            Forms\Components\DateTimePicker::make('qualifying_datetime_utc')->label('Квалификация (UTC)'),
            Forms\Components\DateTimePicker::make('sprint_datetime_utc')->label('Спринт (UTC)'),
            Forms\Components\Toggle::make('has_sprint')->label('Спринт уикенд'),
            Forms\Components\Select::make('pole_driver_id')
                ->label('Pole пилот')
                ->relationship('poleDriver', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName())
                ->searchable(),
            // Ръчно поле — Ergast не дава safety car. null = неизвестно (не се точкува).
            Forms\Components\Select::make('had_safety_car')
                ->label('Имаше ли safety car?')
                ->options([1 => 'Да', 0 => 'Не'])
                ->placeholder('Неизвестно')
                ->helperText('Въведи ръчно след състезанието, за да се точкува тази прогноза.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('round')->label('Кръг')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('season.year')->label('Сезон')->sortable(),
                Tables\Columns\IconColumn::make('has_sprint')->label('Спринт')->boolean(),
                Tables\Columns\TextColumn::make('race_datetime_utc')
                    ->label('Старт')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Sofia')
                    ->sortable(),
                Tables\Columns\IconColumn::make('had_safety_car')
                    ->label('SC')
                    ->boolean()
                    ->placeholder('?'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('season')->relationship('season', 'year'),
            ])
            ->defaultSort('round')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRaces::route('/'),
            'create' => Pages\CreateRace::route('/create'),
            'edit' => Pages\EditRace::route('/{record}/edit'),
        ];
    }
}
