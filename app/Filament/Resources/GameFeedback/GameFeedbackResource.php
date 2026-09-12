<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameFeedback;

use App\Filament\Resources\GameFeedback\Pages\ListGameFeedback;
use App\Filament\Resources\GameFeedback\Pages\ViewGameFeedback;
use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Models\GameFeedback;
use App\Models\GameSession;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GameFeedbackResource extends Resource
{
    protected static ?string $model = GameFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Мнения за играта';

    protected static ?string $modelLabel = 'мнение за играта';

    protected static ?string $pluralModelLabel = 'мнения за играта';

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_admin === true;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'session:id,track_slug,device,mode']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Мнението на играча')->description('Обратна връзка само за играта. Причината е посочена от човека, а не изведена от статистиката.')->schema([
                TextEntry::make('user.name')->label('Играч')->placeholder('Изтрит потребител'),
                TextEntry::make('created_at')->label('Изпратено (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia'),
                TextEntry::make('updated_at')->label('Последна промяна (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia'),
                TextEntry::make('rating')->label('Обща оценка')->suffix(' / 5'),
                TextEntry::make('controls_rating')->label('Управление')->suffix(' / 5')->placeholder('Без отговор'),
                TextEntry::make('performance_rating')->label('Плавност и графика')->suffix(' / 5')->placeholder('Без отговор'),
                TextEntry::make('difficulty')->label('Трудност')->formatStateUsing(fn (string $state): string => GameFeedback::DIFFICULTY_LABELS[$state] ?? $state)->placeholder('Без отговор'),
                TextEntry::make('reason')->label('Защо спря да играе')->formatStateUsing(fn (string $state): string => GameFeedback::REASON_LABELS[$state] ?? $state)->placeholder('Без отговор'),
                TextEntry::make('would_play_again')->label('Би играл отново')->formatStateUsing(fn (string $state): string => GameFeedback::WOULD_PLAY_AGAIN_LABELS[$state] ?? $state)->placeholder('Без отговор'),
                TextEntry::make('comment')->label('Коментар')->placeholder('Без коментар')->columnSpanFull(),
            ])->columns(3)->columnSpanFull(),
            Section::make('Към кое каране е мнението')->schema([
                TextEntry::make('session_id')->label('Каране №')->url(fn (GameFeedback $record): ?string => $record->session_id ? GameSessionResource::getUrl('view', ['record' => $record->session_id]) : null)->placeholder('Общо мнение след предишно играене; не е свързано с конкретно каране'),
                TextEntry::make('session.track_slug')->label('Писта')->placeholder('Няма свързано каране'),
                TextEntry::make('session.device')->label('Устройство')->formatStateUsing(fn (string $state): string => GameSession::DEVICE_LABELS[$state] ?? $state)->placeholder('Няма данни'),
                TextEntry::make('session.mode')->label('Режим')->formatStateUsing(fn (string $state): string => GameSession::MODE_LABELS[$state] ?? $state)->placeholder('Няма данни'),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Играч')->searchable()->placeholder('Изтрит потребител'),
            TextColumn::make('rating')->label('Обща оценка')->suffix(' / 5')->sortable(),
            TextColumn::make('controls_rating')->label('Управление')->suffix(' / 5')->placeholder('—')->sortable(),
            TextColumn::make('performance_rating')->label('Плавност / графика')->suffix(' / 5')->placeholder('—')->sortable(),
            TextColumn::make('difficulty')->label('Трудност')->formatStateUsing(fn (string $state): string => GameFeedback::DIFFICULTY_LABELS[$state] ?? $state)->placeholder('—'),
            TextColumn::make('reason')->label('Причина да спре')->formatStateUsing(fn (string $state): string => GameFeedback::REASON_LABELS[$state] ?? $state)->placeholder('—')->wrap(),
            TextColumn::make('would_play_again')->label('Отново?')->formatStateUsing(fn (string $state): string => GameFeedback::WOULD_PLAY_AGAIN_LABELS[$state] ?? $state)->placeholder('—'),
            TextColumn::make('comment')->label('Коментар')->searchable()->limit(100)->wrap()->placeholder('Без коментар'),
            TextColumn::make('session.track_slug')->label('Писта')->placeholder('Общо мнение'),
            TextColumn::make('session.device')->label('Устройство')->formatStateUsing(fn (string $state): string => GameSession::DEVICE_LABELS[$state] ?? $state)->placeholder('—')->toggleable(),
            TextColumn::make('session_id')->label('Каране №')->url(fn (GameFeedback $record): ?string => $record->session_id ? GameSessionResource::getUrl('view', ['record' => $record->session_id]) : null)->placeholder('—'),
            TextColumn::make('created_at')->label('Изпратено')->dateTime('d.m.Y H:i', 'Europe/Sofia')->sortable(),
        ])->defaultSort('created_at', 'desc')->filters([
            SelectFilter::make('rating')->label('Обща оценка')->options([1 => '1 / 5', 2 => '2 / 5', 3 => '3 / 5', 4 => '4 / 5', 5 => '5 / 5']),
            SelectFilter::make('reason')->label('Причина')->options(GameFeedback::REASON_LABELS),
            SelectFilter::make('difficulty')->label('Трудност')->options(GameFeedback::DIFFICULTY_LABELS),
            SelectFilter::make('would_play_again')->label('Би играл отново')->options(GameFeedback::WOULD_PLAY_AGAIN_LABELS),
            SelectFilter::make('session_id')->label('Каране')->relationship('session', 'id')->searchable(),
            SelectFilter::make('user_id')->label('Играч')->relationship('user', 'name')->searchable(),
            SelectFilter::make('device')->label('Устройство')->options(GameSession::DEVICE_LABELS)->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $device): Builder => $q->whereHas('session', fn (Builder $sessions): Builder => $sessions->where('device', $device)))),
            Filter::make('low_ratings')->label('Ниска оценка (1–2)')->query(fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('rating', '<=', 2)->orWhere('controls_rating', '<=', 2)->orWhere('performance_rating', '<=', 2))),
        ])->recordActions([ViewAction::make()->label('Прочети')])->emptyStateHeading('Все още няма мнения за играта');
    }

    public static function getPages(): array
    {
        return ['index' => ListGameFeedback::route('/'), 'view' => ViewGameFeedback::route('/{record}')];
    }
}
