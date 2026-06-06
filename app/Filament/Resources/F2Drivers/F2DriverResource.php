<?php

namespace App\Filament\Resources\F2Drivers;

use App\Filament\Resources\F2Drivers\Pages\CreateF2Driver;
use App\Filament\Resources\F2Drivers\Pages\EditF2Driver;
use App\Filament\Resources\F2Drivers\Pages\ListF2Drivers;
use App\Filament\Resources\F2Drivers\Schemas\F2DriverForm;
use App\Filament\Resources\F2Drivers\Tables\F2DriversTable;
use App\Models\F2Driver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class F2DriverResource extends Resource
{
    protected static ?string $model = F2Driver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'F2 данни';

    protected static ?string $navigationLabel = 'F2 Пилоти';

    protected static ?string $modelLabel = 'F2 пилот';

    protected static ?string $pluralModelLabel = 'F2 пилоти';

    public static function form(Schema $schema): Schema
    {
        return F2DriverForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return F2DriversTable::configure($table);
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
            'index' => ListF2Drivers::route('/'),
            'create' => CreateF2Driver::route('/create'),
            'edit' => EditF2Driver::route('/{record}/edit'),
        ];
    }
}
