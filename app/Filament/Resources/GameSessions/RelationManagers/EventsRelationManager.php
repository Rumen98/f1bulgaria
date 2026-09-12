<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSessions\RelationManagers;

use App\Filament\Resources\GameSessions\GameSessionPresentation;
use App\Models\GameSessionEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Хронология на карането';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Подредено по последователност в браузъра. Времето е активно време от старта; часът на получаване може да е по-късен при забавена връзка. Изключи „Само важните събития“, за да видиш периодичните отчети за скорост, кадри/сек и графика.')
            ->columns([
                TextColumn::make('sequence')->label('№')->sortable(),
                TextColumn::make('active_ms')->label('След старта')->formatStateUsing(fn (int $state): string => GameSessionPresentation::duration($state)),
                TextColumn::make('type')->label('Събитие')->formatStateUsing(fn (string $state): string => GameSessionPresentation::EVENT_LABELS[$state] ?? $state)->badge(),
                TextColumn::make('details')->label('Подробности')->state(fn (GameSessionEvent $record): array => GameSessionPresentation::eventDetails($record))->listWithLineBreaks()->wrap(),
                TextColumn::make('received_at')->label('Получено (София)')->dateTime('d.m.Y H:i:s', 'Europe/Sofia')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                Filter::make('milestones')->label('Само важните събития')->default()->query(fn (Builder $query): Builder => $query->where('type', '!=', 'heartbeat')),
                SelectFilter::make('type')->label('Вид събитие')->options(GameSessionPresentation::EVENT_LABELS),
            ])->defaultSort('sequence')->defaultPaginationPageOption(25)->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Няма получени събития за тези филтри')
            ->emptyStateDescription('Старите записи съдържат само старт. При ново каране историята се попълва от браузъра.');
    }
}
