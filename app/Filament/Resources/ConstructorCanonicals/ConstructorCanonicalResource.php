<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConstructorCanonicals;

use App\Filament\Resources\ConstructorCanonicals\Pages\EditConstructorCanonical;
use App\Filament\Resources\ConstructorCanonicals\Pages\ListConstructorCanonicals;
use App\Filament\Resources\ConstructorCanonicals\Schemas\ConstructorCanonicalForm;
use App\Filament\Resources\ConstructorCanonicals\Tables\ConstructorCanonicalsTable;
use App\Models\ConstructorCanonical;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ConstructorCanonicalResource extends Resource
{
    protected static ?string $model = ConstructorCanonical::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|\UnitEnum|null $navigationGroup = 'Каноничен модел';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Отбори (канонични)';

    protected static ?string $modelLabel = 'каноничен отбор';

    protected static ?string $pluralModelLabel = 'канонични отбори';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ConstructorCanonicalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConstructorCanonicalsTable::configure($table);
    }

    /**
     * Без Create — каноничните записи се изграждат от constructors:backfill-canonical,
     * не ръчно. Тук само се редактират curated полета (лого, био и т.н.).
     */
    public static function getPages(): array
    {
        return [
            'index' => ListConstructorCanonicals::route('/'),
            'edit' => EditConstructorCanonical::route('/{record}/edit'),
        ];
    }
}
