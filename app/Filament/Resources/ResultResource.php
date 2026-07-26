<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ResultResource\Pages\CreateResult;
use App\Filament\Resources\ResultResource\Pages\EditResult;
use App\Filament\Resources\ResultResource\Pages\ListResults;
use App\Models\Result;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ResultResource extends Resource
{
    protected static ?string $model = Result::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static string|\UnitEnum|null $navigationGroup = 'Данни от Формула 1';

    protected static ?string $navigationLabel = 'Резултати';

    protected static ?string $modelLabel = 'резултат';

    protected static ?string $pluralModelLabel = 'резултати';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('race_id')
                ->label('Състезание')
                ->relationship('race', 'name')
                ->searchable()
                ->required(),
            Select::make('driver_id')
                ->label('Пилот')
                ->relationship('driver', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullName())
                ->searchable()
                ->required(),
            TextInput::make('position')->label('Позиция')->numeric(),
            TextInput::make('points')->label('Точки')->numeric()->required()->default(0),
            TextInput::make('grid_position')->label('Стартова позиция')->numeric(),
            Toggle::make('dnf')->label('DNF (отпаднал)'),
            Toggle::make('fastest_lap')->label('Най-бърза обиколка'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('race.name')->label('Състезание')->searchable()->sortable(),
                TextColumn::make('position')->label('Поз.')->placeholder('DNF')->sortable(),
                TextColumn::make('driver.last_name')
                    ->label('Пилот')
                    ->formatStateUsing(fn (Result $r) => $r->driver?->fullName())
                    ->searchable(),
                TextColumn::make('points')->label('Точки'),
                IconColumn::make('dnf')->label('DNF')->boolean(),
                IconColumn::make('fastest_lap')->label('FL')->boolean(),
            ])
            ->filters([
                SelectFilter::make('race')->relationship('race', 'name'),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResults::route('/'),
            'create' => CreateResult::route('/create'),
            'edit' => EditResult::route('/{record}/edit'),
        ];
    }
}
