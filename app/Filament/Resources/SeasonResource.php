<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SeasonResource\Pages;
use App\Models\Season;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Сезони';

    protected static ?string $modelLabel = 'сезон';

    protected static ?string $pluralModelLabel = 'сезони';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('year')
                ->label('Година')
                ->numeric()
                ->required()
                ->unique(ignoreRecord: true),
            Forms\Components\Toggle::make('is_current')
                ->label('Текущ сезон'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('year')->label('Година')->sortable(),
                Tables\Columns\IconColumn::make('is_current')->label('Текущ')->boolean(),
                Tables\Columns\TextColumn::make('races_count')->label('Състезания')->counts('races'),
                Tables\Columns\TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
            ])
            ->defaultSort('year', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeasons::route('/'),
            'create' => Pages\CreateSeason::route('/create'),
            'edit' => Pages\EditSeason::route('/{record}/edit'),
        ];
    }
}
