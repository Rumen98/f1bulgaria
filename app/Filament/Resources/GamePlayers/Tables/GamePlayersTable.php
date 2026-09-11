<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePlayers\Tables;

use App\Models\GameSession;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GamePlayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::withPlayedAt($query))
            ->columns([
                TextColumn::make('name')->label('Играч')->searchable()->sortable(),
                TextColumn::make('email')->label('Имейл')->searchable()->toggleable(),
                TextColumn::make('first_played_at')
                    ->label('Първо каране')
                    ->dateTime('d.m.Y H:i', 'Europe/Sofia')
                    ->sortable(),
                TextColumn::make('last_played_at')
                    ->label('Последно')
                    ->since()
                    ->dateTimeTooltip('d.m.Y H:i', 'Europe/Sofia')
                    ->sortable(),
                TextColumn::make('game_sessions_count')
                    ->label('Стартове')
                    ->counts('gameSessions')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('mobile_sessions_count')
                    ->label('Устройство')
                    ->counts([
                        'gameSessions as mobile_sessions_count' => fn (Builder $query): Builder => $query
                            ->where('device', GameSession::DEVICE_MOBILE),
                    ])
                    ->formatStateUsing(fn (User $record): string => self::deviceSummary($record)),
                TextColumn::make('game_lap_records_count')
                    ->label('Обиколки')
                    ->counts('gameLapRecords')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tracks_count')
                    ->label('Писти')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('game_lap_records_min_lap_ms')
                    ->label('Най-добра')
                    ->min(['gameLapRecords' => fn (Builder $query): Builder => $query->counted()], 'lap_ms')
                    ->formatStateUsing(fn (int $state): string => self::formatLap($state))
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('last_played_at', 'desc')
            ->filters([
                Filter::make('with_laps')
                    ->label('Със записана обиколка')
                    ->query(fn (Builder $query): Builder => $query->whereHas('gameLapRecords')),
                Filter::make('without_laps')
                    ->label('Стартирали, без завършена обиколка')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('gameLapRecords')),
                Filter::make('mobile')
                    ->label('Карали на телефон')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'gameSessions',
                        fn (Builder $sessions): Builder => $sessions->where('device', GameSession::DEVICE_MOBILE)
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('exportCsv')
                        ->label('Експорт CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (Collection $records): StreamedResponse => self::exportCsv($records)),
                ]),
            ]);
    }

    /**
     * Първо/последно каране = най-ранното/най-късното от двете таблици.
     * Стартовете (game_sessions) се записват от 11.09.2026; по-старите
     * играчи имат само обиколки — затова MIN/MAX се сливат, а не се взима
     * едната таблица. Скаларни подзаявки вместо UNION в derived table:
     * MySQL не позволява корелация към users.id вътре във FROM подзаявка.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function withPlayedAt(Builder $query): Builder
    {
        $tracks = '(SELECT COUNT(DISTINCT track_slug) FROM game_lap_records WHERE game_lap_records.user_id = users.id)';

        return $query
            ->select('users.*')
            ->selectRaw(self::playedAtExpression('MIN').' AS first_played_at')
            ->selectRaw(self::playedAtExpression('MAX').' AS last_played_at')
            ->selectRaw("{$tracks} AS tracks_count");
    }

    /**
     * @param  'MIN'|'MAX'  $aggregate
     */
    private static function playedAtExpression(string $aggregate): string
    {
        $sessions = "(SELECT {$aggregate}(created_at) FROM game_sessions WHERE game_sessions.user_id = users.id)";
        $laps = "(SELECT {$aggregate}(created_at) FROM game_lap_records WHERE game_lap_records.user_id = users.id)";
        $operator = $aggregate === 'MIN' ? '<' : '>';

        return "CASE WHEN {$sessions} IS NULL THEN {$laps}"
            ." WHEN {$laps} IS NULL THEN {$sessions}"
            ." WHEN {$sessions} {$operator} {$laps} THEN {$sessions}"
            ." ELSE {$laps} END";
    }

    private static function deviceSummary(User $record): string
    {
        $mobile = (int) ($record->mobile_sessions_count ?? 0);
        $desktop = (int) ($record->game_sessions_count ?? 0) - $mobile;

        if ($mobile === 0 && $desktop === 0) {
            return '—'; // само стари обиколки, преди да се записват стартовете
        }

        $parts = [];
        if ($desktop > 0) {
            $parts[] = "{$desktop}× компютър";
        }
        if ($mobile > 0) {
            $parts[] = "{$mobile}× телефон";
        }

        return implode(' · ', $parts);
    }

    public static function formatLap(int $ms): string
    {
        return sprintf('%d:%06.3f', intdiv($ms, 60000), ($ms % 60000) / 1000);
    }

    /**
     * Потребителски низ в CSV: клетка, започваща с =, +, - или @, Excel/Sheets
     * изпълняват като формула. Водещият апостроф я оставя текст.
     */
    public static function csvSafe(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@	]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Избраните редове минават наново през заявката с агрегатите — bulk
     * action-ът получава моделите по ключ, без подзаявките на таблицата.
     *
     * @param  Collection<int, User>  $records
     */
    private static function exportCsv(Collection $records): StreamedResponse
    {
        $players = self::withPlayedAt(User::query()->whereKey($records->modelKeys()))
            ->withCount('gameSessions', 'gameLapRecords')
            ->withMin(['gameLapRecords' => fn (Builder $query): Builder => $query->counted()], 'lap_ms')
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($players): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'email', 'first_played_at', 'last_played_at', 'starts', 'laps', 'tracks', 'best_lap']);

            foreach ($players as $player) {
                $best = $player->game_lap_records_min_lap_ms;
                fputcsv($out, [
                    self::csvSafe($player->name),
                    self::csvSafe($player->email),
                    $player->first_played_at,
                    $player->last_played_at,
                    $player->game_sessions_count,
                    $player->game_lap_records_count,
                    $player->tracks_count,
                    $best === null ? '' : self::formatLap((int) $best),
                ]);
            }

            fclose($out);
        }, 'game-players.csv', ['Content-Type' => 'text/csv']);
    }
}
