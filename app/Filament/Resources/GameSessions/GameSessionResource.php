<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSessions;

use App\Filament\Resources\GameFeedback\GameFeedbackResource;
use App\Filament\Resources\GameSessions\Pages\ListGameSessions;
use App\Filament\Resources\GameSessions\Pages\ViewGameSession;
use App\Filament\Resources\GameSessions\RelationManagers\EventsRelationManager;
use App\Models\GameSession;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
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

class GameSessionResource extends Resource
{
    protected static ?string $model = GameSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Общност';

    protected static ?string $navigationLabel = 'Карания — подробности';

    protected static ?string $modelLabel = 'каране';

    protected static ?string $pluralModelLabel = 'карания';

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
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Какво е отчетено')->description('Данни от браузъра на регистриран играч. Това е история на карането, а не проверен резултат за класацията.')->schema([
                TextEntry::make('user.name')->label('Играч')->placeholder('Изтрит потребител'),
                TextEntry::make('track_slug')->label('Писта'),
                TextEntry::make('mode')->label('Режим')->formatStateUsing(fn (string $state): string => GameSession::MODE_LABELS[$state] ?? $state),
                TextEntry::make('status')->label('Последно известно състояние')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::status($record))->badge()->color(fn (GameSession $record): string => GameSessionPresentation::statusColor($record)),
                TextEntry::make('created_at')->label('Начало (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia'),
                TextEntry::make('last_seen_at')->label('Последно получени данни (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia')->placeholder('Няма подробна история'),
                TextEntry::make('ended_at')->label('Получен краен сигнал (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia')->placeholder('Няма краен сигнал'),
                TextEntry::make('active_ms')->label('Отчетено активно време')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::duration($record->status === 'legacy' ? null : $record->active_ms)),
                TextEntry::make('lap_count')->label('Завършени обиколки')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::laps($record)),
                TextEntry::make('max_progress')->label('Максимален напредък в обиколка')->formatStateUsing(fn (GameSession $record): string => $record->status === 'legacy' ? 'Няма данни' : round($record->max_progress * 100).'%')->helperText('За хронометрирана обиколка; 100% се отчита и при невалиден финал.'),
                TextEntry::make('last_sector')->label('Последно отчетен сектор')->placeholder('Няма данни'),
                TextEntry::make('max_speed')->label('Максимална отчетена скорост')->formatStateUsing(fn (GameSession $record): string => $record->status === 'legacy' ? 'Няма данни' : round($record->max_speed).' км/ч'),
                TextEntry::make('interpretation')->label('Как да четеш тези данни')->state(fn (GameSession $record): string => GameSessionPresentation::interpretation($record))->columnSpanFull(),
            ])->columns(3)->columnSpanFull(),
            Section::make('Устройство и настройки')->schema([
                TextEntry::make('device')->label('Тип устройство')->formatStateUsing(fn (string $state): string => GameSession::DEVICE_LABELS[$state] ?? $state),
                RepeatableEntry::make('diagnostics')->label('Настройки при старта')->state(fn (GameSession $record): array => GameSessionPresentation::context($record))->schema([
                    TextEntry::make('label')->hiddenLabel(),
                    TextEntry::make('value')->hiddenLabel(),
                ])->columns(2)->contained(false)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('user.name')->label('Играч')->searchable()->placeholder('Изтрит потребител'),
            TextColumn::make('created_at')->label('Начало')->dateTime('d.m.Y H:i', 'Europe/Sofia')->sortable(),
            TextColumn::make('track_slug')->label('Писта')->searchable(),
            TextColumn::make('mode')->label('Режим')->formatStateUsing(fn (string $state): string => GameSession::MODE_LABELS[$state] ?? $state),
            TextColumn::make('device')->label('Устройство')->formatStateUsing(fn (string $state): string => GameSession::DEVICE_LABELS[$state] ?? $state),
            TextColumn::make('status')->label('Последно известно')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::status($record))->badge()->color(fn (GameSession $record): string => GameSessionPresentation::statusColor($record)),
            TextColumn::make('active_ms')->label('Активно време')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::duration($record->status === 'legacy' ? null : $record->active_ms))->sortable(),
            TextColumn::make('lap_count')->label('Обиколки: общо / чисти / невалидни')->formatStateUsing(fn (GameSession $record): string => GameSessionPresentation::laps($record))->sortable()->wrap(),
            TextColumn::make('max_progress')->label('Напредък')->formatStateUsing(fn (GameSession $record): string => $record->status === 'legacy' ? 'Няма данни' : round($record->max_progress * 100).'%')->sortable(),
            TextColumn::make('last_sector')->label('Последен сектор')->placeholder('—')->toggleable(),
            TextColumn::make('max_speed')->label('Макс. км/ч')->formatStateUsing(fn (GameSession $record): string => $record->status === 'legacy' ? '—' : (string) round($record->max_speed))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_seen_at')->label('Последни данни')->dateTime('d.m.Y H:i:s', 'Europe/Sofia')->placeholder('Няма подробна история')->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('created_at', 'desc')->filters([
            SelectFilter::make('user_id')->label('Играч')->relationship('user', 'name')->searchable(),
            SelectFilter::make('device')->label('Устройство')->options(GameSession::DEVICE_LABELS),
            SelectFilter::make('mode')->label('Режим')->options(GameSession::MODE_LABELS),
            SelectFilter::make('track_slug')->label('Писта')->options(fn (): array => GameSession::query()->distinct()->orderBy('track_slug')->pluck('track_slug', 'track_slug')->all()),
            SelectFilter::make('status')->label('Състояние')->options(GameSessionPresentation::STATUS_LABELS + ['stale' => 'Няма скорошни данни'])->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;
                if ($value === 'stale') {
                    return $query->whereIn('status', ['active', 'paused'])->where(fn (Builder $q): Builder => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(2)));
                }
                if (in_array($value, ['active', 'paused'], true)) {
                    return $query->where('status', $value)->where('last_seen_at', '>=', now()->subMinutes(2));
                }

                return $query->when($value, fn (Builder $q): Builder => $q->where('status', $value));
            }),
            Filter::make('has_laps')->label('С отчетен финал на обиколка')->query(fn (Builder $query): Builder => $query->where('lap_count', '>', 0)),
            Filter::make('without_laps')->label('Подробна история, без отчетен финал')->query(fn (Builder $query): Builder => $query->where('status', '!=', 'legacy')->where('lap_count', 0)),
            Filter::make('invalid_laps')->label('С невалидни завършени обиколки')->query(fn (Builder $query): Builder => $query->where('invalid_lap_count', '>', 0)),
            Filter::make('save_failed')->label('Проблем при запис в класацията')->query(fn (Builder $query): Builder => $query->whereHas('events', fn (Builder $events): Builder => $events->where('type', 'lap_save_failed'))),
            Filter::make('period')->label('Период на старта (София)')->schema([
                DatePicker::make('from')->label('От'), DatePicker::make('until')->label('До'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->where('created_at', '>=', CarbonImmutable::parse($date, 'Europe/Sofia')->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->where('created_at', '<=', CarbonImmutable::parse($date, 'Europe/Sofia')->endOfDay()->utc()))),
        ])->recordActions([
            ViewAction::make()->label('История'),
            Action::make('feedback')->label('Мнения')->url(fn (GameSession $record): string => GameFeedbackResource::getUrl('index', ['filters' => ['session_id' => ['value' => $record->id]]])),
        ])->emptyStateHeading('Все още няма отчетени карания')->emptyStateDescription('Подробните събития ще се появят след обновяването на играта и ново каране.');
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListGameSessions::route('/'), 'view' => ViewGameSession::route('/{record}')];
    }
}
