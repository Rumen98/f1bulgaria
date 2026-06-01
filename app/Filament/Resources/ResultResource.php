<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ResultResource\Pages;
use App\Models\Result;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ResultResource extends Resource
{
    protected static ?string $model = Result::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Резултати';

    protected static ?string $modelLabel = 'резултат';

    protected static ?string $pluralModelLabel = 'резултати';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('race_id')
                ->label('Състезание')
                ->relationship('race', 'name')
                ->searchable()
                ->required(),
            Forms\Components\Select::make('driver_id')
                ->label('Пилот')
                ->relationship('driver', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName())
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('position')->label('Позиция')->numeric(),
            Forms\Components\TextInput::make('points')->label('Точки')->numeric()->required()->default(0),
            Forms\Components\TextInput::make('grid_position')->label('Стартова позиция')->numeric(),
            Forms\Components\Toggle::make('dnf')->label('DNF (отпаднал)'),
            Forms\Components\Toggle::make('fastest_lap')->label('Най-бърза обиколка'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('race.name')->label('Състезание')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('position')->label('Поз.')->placeholder('DNF')->sortable(),
                Tables\Columns\TextColumn::make('driver.last_name')
                    ->label('Пилот')
                    ->formatStateUsing(fn (Result $r) => $r->driver?->fullName())
                    ->searchable(),
                Tables\Columns\TextColumn::make('points')->label('Точки'),
                Tables\Columns\IconColumn::make('dnf')->label('DNF')->boolean(),
                Tables\Columns\IconColumn::make('fastest_lap')->label('FL')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('race')->relationship('race', 'name'),
            ])
            ->defaultSort('position')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResults::route('/'),
            'create' => Pages\CreateResult::route('/create'),
            'edit' => Pages\EditResult::route('/{record}/edit'),
        ];
    }
}
