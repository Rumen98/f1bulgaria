<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ConstructorResource\Pages\CreateConstructor;
use App\Filament\Resources\ConstructorResource\Pages\EditConstructor;
use App\Filament\Resources\ConstructorResource\Pages\ListConstructors;
use App\Models\Constructor;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConstructorResource extends Resource
{
    protected static ?string $model = Constructor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|\UnitEnum|null $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Конструктори';

    protected static ?string $modelLabel = 'конструктор';

    protected static ?string $pluralModelLabel = 'конструктори';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('season_id')
                ->label('Сезон')
                ->relationship('season', 'year')
                ->required(),
            TextInput::make('name')->label('Име')->required(),
            TextInput::make('slug')->label('Slug')->required(),
            ColorPicker::make('color_hex')->label('Цвят'),
            TextInput::make('jolpica_id')
                ->label('Jolpica ID')
                ->helperText('Идентификатор за синхрона — променяй с внимание.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color_hex')->label('Цвят'),
                TextColumn::make('name')->label('Име')->searchable()->sortable(),
                TextColumn::make('season.year')->label('Сезон')->sortable(),
                TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
            ])
            ->filters([
                SelectFilter::make('season')->relationship('season', 'year'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConstructors::route('/'),
            'create' => CreateConstructor::route('/create'),
            'edit' => EditConstructor::route('/{record}/edit'),
        ];
    }
}
