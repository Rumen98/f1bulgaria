<?php

namespace App\Filament\Resources\F2Seasons;

use App\Filament\Resources\F2Seasons\Pages\CreateF2Season;
use App\Filament\Resources\F2Seasons\Pages\EditF2Season;
use App\Filament\Resources\F2Seasons\Pages\ListF2Seasons;
use App\Filament\Resources\F2Seasons\Schemas\F2SeasonForm;
use App\Filament\Resources\F2Seasons\Tables\F2SeasonsTable;
use App\Models\F2Season;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class F2SeasonResource extends Resource
{
    protected static ?string $model = F2Season::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'F2 данни';

    protected static ?string $navigationLabel = 'F2 Сезони';

    protected static ?string $modelLabel = 'F2 сезон';

    protected static ?string $pluralModelLabel = 'F2 сезони';

    public static function form(Schema $schema): Schema
    {
        return F2SeasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return F2SeasonsTable::configure($table);
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
            'index' => ListF2Seasons::route('/'),
            'create' => CreateF2Season::route('/create'),
            'edit' => EditF2Season::route('/{record}/edit'),
        ];
    }
}
