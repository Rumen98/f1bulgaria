<?php

namespace App\Filament\Resources\F2Teams;

use App\Filament\Resources\F2Teams\Pages\CreateF2Team;
use App\Filament\Resources\F2Teams\Pages\EditF2Team;
use App\Filament\Resources\F2Teams\Pages\ListF2Teams;
use App\Filament\Resources\F2Teams\Schemas\F2TeamForm;
use App\Filament\Resources\F2Teams\Tables\F2TeamsTable;
use App\Models\F2Team;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class F2TeamResource extends Resource
{
    protected static ?string $model = F2Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|\UnitEnum|null $navigationGroup = 'F2 данни';

    protected static ?string $navigationLabel = 'F2 Отбори';

    protected static ?string $modelLabel = 'F2 отбор';

    protected static ?string $pluralModelLabel = 'F2 отбори';

    public static function form(Schema $schema): Schema
    {
        return F2TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return F2TeamsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListF2Teams::route('/'),
            'create' => CreateF2Team::route('/create'),
            'edit' => EditF2Team::route('/{record}/edit'),
        ];
    }
}
