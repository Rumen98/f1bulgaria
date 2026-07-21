<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments;

use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Comments\Tables\CommentsTable;
use App\Models\Comment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Коментари';

    protected static ?string $modelLabel = 'коментар';

    protected static ?string $pluralModelLabel = 'коментари';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return CommentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // TrashedFilter има нужда от изтритите записи (модерация със следа).
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Само списък — коментарите се пишат от сайта, тук само се модерират.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
        ];
    }
}
