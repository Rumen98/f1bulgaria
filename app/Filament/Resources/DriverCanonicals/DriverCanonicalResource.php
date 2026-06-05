<?php

declare(strict_types=1);

namespace App\Filament\Resources\DriverCanonicals;

use App\Filament\Resources\DriverCanonicals\Pages\EditDriverCanonical;
use App\Filament\Resources\DriverCanonicals\Pages\ListDriverCanonicals;
use App\Filament\Resources\DriverCanonicals\Schemas\DriverCanonicalForm;
use App\Filament\Resources\DriverCanonicals\Tables\DriverCanonicalsTable;
use App\Models\DriverCanonical;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DriverCanonicalResource extends Resource
{
    protected static ?string $model = DriverCanonical::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|\UnitEnum|null $navigationGroup = 'Каноничен модел';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Пилоти (канонични)';

    protected static ?string $modelLabel = 'каноничен пилот';

    protected static ?string $pluralModelLabel = 'канонични пилоти';

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function form(Schema $schema): Schema
    {
        return DriverCanonicalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriverCanonicalsTable::configure($table);
    }

    /**
     * Без Create — каноничните записи се изграждат от drivers:backfill-canonical,
     * не ръчно. Тук само се редактират curated полета (снимка, био и т.н.).
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDriverCanonicals::route('/'),
            'edit' => EditDriverCanonical::route('/{record}/edit'),
        ];
    }
}
