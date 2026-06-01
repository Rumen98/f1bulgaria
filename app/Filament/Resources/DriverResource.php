<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages;
use App\Models\Driver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Пилоти';

    protected static ?string $modelLabel = 'пилот';

    protected static ?string $pluralModelLabel = 'пилоти';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('season_id')
                ->label('Сезон')
                ->relationship('season', 'year')
                ->required(),
            Forms\Components\Select::make('constructor_id')
                ->label('Конструктор')
                ->relationship('constructor', 'name'),
            Forms\Components\TextInput::make('first_name')->label('Име')->required(),
            Forms\Components\TextInput::make('last_name')->label('Фамилия')->required(),
            Forms\Components\TextInput::make('slug')->label('Slug')->required(),
            Forms\Components\TextInput::make('driver_code')->label('Код (напр. HAM)')->maxLength(3),
            Forms\Components\TextInput::make('permanent_number')->label('Номер')->numeric(),
            Forms\Components\TextInput::make('country_code')->label('Държава (ISO3)')->maxLength(3),
            Forms\Components\TextInput::make('jolpica_id')
                ->label('Jolpica ID')
                ->helperText('Идентификатор за синхрона — променяй с внимание.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Пилот')
                    ->formatStateUsing(fn (Driver $r) => $r->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver_code')->label('Код'),
                Tables\Columns\TextColumn::make('constructor.name')->label('Отбор'),
                Tables\Columns\TextColumn::make('season.year')->label('Сезон')->sortable(),
                Tables\Columns\TextColumn::make('permanent_number')->label('№'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('season')->relationship('season', 'year'),
                Tables\Filters\SelectFilter::make('constructor')->relationship('constructor', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
