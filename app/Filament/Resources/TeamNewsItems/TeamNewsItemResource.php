<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamNewsItems;

use App\Filament\Resources\TeamNewsItems\Pages\EditTeamNewsItem;
use App\Filament\Resources\TeamNewsItems\Pages\ListTeamNewsItems;
use App\Filament\Resources\TeamNewsItems\Schemas\TeamNewsItemForm;
use App\Filament\Resources\TeamNewsItems\Tables\TeamNewsItemsTable;
use App\Models\TeamNewsItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TeamNewsItemResource extends Resource
{
    protected static ?string $model = TeamNewsItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'Новини';

    protected static ?string $navigationLabel = 'Новини';

    protected static ?string $modelLabel = 'новина';

    protected static ?string $pluralModelLabel = 'новини';

    protected static ?string $recordTitleAttribute = 'title_bg';

    public static function form(Schema $schema): Schema
    {
        return TeamNewsItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamNewsItemsTable::configure($table);
    }

    /**
     * Без Create — новините идват от RSS pipeline-а (news:fetch), не ръчно.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTeamNewsItems::route('/'),
            'edit' => EditTeamNewsItem::route('/{record}/edit'),
        ];
    }
}
