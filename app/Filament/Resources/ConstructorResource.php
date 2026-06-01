<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ConstructorResource\Pages;
use App\Models\Constructor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConstructorResource extends Resource
{
    protected static ?string $model = Constructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Конструктори';

    protected static ?string $modelLabel = 'конструктор';

    protected static ?string $pluralModelLabel = 'конструктори';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('season_id')
                ->label('Сезон')
                ->relationship('season', 'year')
                ->required(),
            Forms\Components\TextInput::make('name')->label('Име')->required(),
            Forms\Components\TextInput::make('slug')->label('Slug')->required(),
            Forms\Components\ColorPicker::make('color_hex')->label('Цвят'),
            Forms\Components\TextInput::make('jolpica_id')
                ->label('Jolpica ID')
                ->helperText('Идентификатор за синхрона — променяй с внимание.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color_hex')->label('Цвят'),
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('season.year')->label('Сезон')->sortable(),
                Tables\Columns\TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('season')->relationship('season', 'year'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConstructors::route('/'),
            'create' => Pages\CreateConstructor::route('/create'),
            'edit' => Pages\EditConstructor::route('/{record}/edit'),
        ];
    }
}
