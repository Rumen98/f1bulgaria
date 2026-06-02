<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Models\Season;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static string|\UnitEnum|null $navigationGroup = 'F1 данни';

    protected static ?string $navigationLabel = 'Сезони';

    protected static ?string $modelLabel = 'сезон';

    protected static ?string $pluralModelLabel = 'сезони';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('year')
                ->label('Година')
                ->numeric()
                ->required()
                ->unique(ignoreRecord: true),
            Toggle::make('is_current')
                ->label('Текущ сезон'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label('Година')->sortable(),
                IconColumn::make('is_current')->label('Текущ')->boolean(),
                TextColumn::make('races_count')->label('Състезания')->counts('races'),
                TextColumn::make('drivers_count')->label('Пилоти')->counts('drivers'),
            ])
            ->defaultSort('year', 'desc')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeasons::route('/'),
            'create' => CreateSeason::route('/create'),
            'edit' => EditSeason::route('/{record}/edit'),
        ];
    }
}
