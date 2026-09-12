<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePlayers;

use App\Filament\Resources\GamePlayers\Pages\ListGamePlayers;
use App\Filament\Resources\GamePlayers\Tables\GamePlayersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Кой е пробвал играта: потребителите с поне едно натискане на „Карай"
 * (game_sessions) или записана обиколка (game_lap_records), с първо/последно
 * каране, брой стартове, обиколки и най-добро време. Само за четене —
 * данните идват от самата игра.
 *
 * Втори ресурс върху User (UserResource е за модерация), затова има свой
 * slug — иначе двата биха делили /admin/users.
 */
class GamePlayerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'game-players';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Играчи';

    protected static ?string $modelLabel = 'играч';

    protected static ?string $pluralModelLabel = 'играчи';

    protected static ?string $recordTitleAttribute = 'name';

    /** Няма view/edit страница — глобалното търсене няма къде да води. */
    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_admin === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Броят на пробвалите играта — видим и без да се отваря списъкът. */
    public static function getNavigationBadge(): ?string
    {
        return (string) static::playersQuery()->count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Потребители, пробвали играта';
    }

    /**
     * @return Builder<User>
     */
    public static function playersQuery(): Builder
    {
        return User::query()->where(function (Builder $query): void {
            $query->whereHas('gameSessions')->orWhereHas('gameLapRecords');
        });
    }

    public static function getEloquentQuery(): Builder
    {
        return static::playersQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return GamePlayersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGamePlayers::route('/'),
        ];
    }
}
