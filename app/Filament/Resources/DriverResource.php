<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DriverResource\Pages\CreateDriver;
use App\Filament\Resources\DriverResource\Pages\EditDriver;
use App\Filament\Resources\DriverResource\Pages\ListDrivers;
use App\Models\Driver;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Записи по сезон';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Пилоти (по сезон)';

    protected static ?string $modelLabel = 'пилот';

    protected static ?string $pluralModelLabel = 'пилоти';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('season_id')
                ->label('Сезон')
                ->relationship('season', 'year')
                ->required(),
            Select::make('constructor_id')
                ->label('Конструктор')
                ->relationship('constructor', 'name'),
            TextInput::make('first_name')->label('Име')->required(),
            TextInput::make('last_name')->label('Фамилия')->required(),
            TextInput::make('slug')->label('Slug')->required(),
            TextInput::make('driver_code')->label('Код (напр. HAM)')->maxLength(3),
            TextInput::make('permanent_number')->label('Номер')->numeric(),
            TextInput::make('country_code')->label('Държава (ISO3)')->maxLength(3),
            TextInput::make('jolpica_id')
                ->label('Jolpica ID')
                ->helperText('Идентификатор за синхрона — променяй с внимание.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Пилот')
                    ->formatStateUsing(fn (Driver $r) => $r->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('driver_code')->label('Код'),
                TextColumn::make('constructor.name')->label('Отбор'),
                TextColumn::make('season.year')->label('Сезон')->sortable(),
                TextColumn::make('permanent_number')->label('№'),
            ])
            ->filters([
                SelectFilter::make('season')->relationship('season', 'year'),
                SelectFilter::make('constructor')->relationship('constructor', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }
}
