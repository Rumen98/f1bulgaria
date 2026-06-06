<?php

namespace App\Filament\Resources\Rivalries;

use App\Filament\Resources\Rivalries\Pages\CreateRivalry;
use App\Filament\Resources\Rivalries\Pages\EditRivalry;
use App\Filament\Resources\Rivalries\Pages\ListRivalries;
use App\Filament\Resources\Rivalries\Schemas\RivalryForm;
use App\Filament\Resources\Rivalries\Tables\RivalriesTable;
use App\Models\Rivalry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RivalryResource extends Resource
{
    protected static ?string $model = Rivalry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Съперничества';

    protected static ?string $modelLabel = 'съперничество';

    protected static ?string $pluralModelLabel = 'съперничества';

    protected static ?string $recordTitleAttribute = 'title_bg';

    public static function form(Schema $schema): Schema
    {
        return RivalryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RivalriesTable::configure($table);
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
            'index' => ListRivalries::route('/'),
            'create' => CreateRivalry::route('/create'),
            'edit' => EditRivalry::route('/{record}/edit'),
        ];
    }
}
