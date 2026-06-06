<?php

declare(strict_types=1);

namespace App\Services\F2;

use App\Enums\F2SessionType;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\F2Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Синхронизира F2 сезон от Wikipedia (season page → round pages → results).
 * Wikipedia е източникът на истината: пилоти/отбори се създават при нужда.
 * Идемпотентно (updateOrCreate). Без измисляне на данни — липсва → се пропуска.
 */
class F2WikipediaSync
{
    /** @var array<string, int> */
    private array $stats = ['races' => 0, 'sessions' => 0, 'results' => 0, 'rounds_skipped' => 0];

    public function __construct(
        private readonly WikipediaClient $client,
        private readonly WikitextParser $parser,
    ) {}

    /**
     * Синхронизира една F2 година. $onRound(callback) за прогрес.
     *
     * @return array{season:?int, rounds:int, races:int, results:int, drivers:int, teams:int, skipped:int}
     */
    public function syncYear(int $year, ?callable $onRound = null): array
    {
        $seasonWikitext = $this->client->getSeasonPage($year);

        if ($seasonWikitext === null) {
            return ['season' => null, 'rounds' => 0, 'races' => 0, 'results' => 0, 'drivers' => 0, 'teams' => 0, 'skipped' => 0];
        }

        $rounds = $this->parser->parseSeasonPage($seasonWikitext)['rounds'];

        // Текущ сезон = най-новата година (другите не са current).
        $isCurrent = $year >= (int) (F2Season::query()->max('year') ?? $year);
        $season = F2Season::query()->updateOrCreate(['year' => $year], ['is_current' => $isCurrent]);

        foreach ($rounds as $index => $title) {
            $roundNo = $index + 1;
            $wikitext = $this->client->getPage($title);

            if ($wikitext === null) {
                $this->stats['rounds_skipped']++;
                if ($onRound !== null) {
                    $onRound($roundNo, $title, false);
                }

                continue;
            }

            $this->syncRound($season, $title, $roundNo, $wikitext);
            if ($onRound !== null) {
                $onRound($roundNo, $title, true);
            }
        }

        $this->computeStandings($season);

        return [
            'season' => $year,
            'rounds' => count($rounds),
            'races' => $this->stats['races'],
            'results' => $this->stats['results'],
            'drivers' => $season->drivers()->count(),
            'teams' => $season->teams()->count(),
            'skipped' => $this->stats['rounds_skipped'],
        ];
    }

    private function syncRound(F2Season $season, string $title, int $roundNo, string $wikitext): void
    {
        $parsed = $this->parser->parseRoundPage($wikitext);
        $location = $this->locationFromTitle($title, $season->year);

        $race = F2Race::query()->updateOrCreate(
            ['f2_season_id' => $season->id, 'round' => $roundNo],
            [
                'location_name' => $location,
                'circuit_jolpica_id' => config("f2-circuit-map.{$location}"),
                'slug' => Str::slug("{$season->year}-{$location}"),
                'wikipedia_url' => 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title),
            ],
        );

        $this->stats['races']++;

        $this->syncSession($season, $race, F2SessionType::SprintRace, $parsed['sprint']);
        $this->syncSession($season, $race, F2SessionType::FeatureRace, $parsed['feature'], $parsed['pole_driver']);
    }

    /**
     * @param  array{results:Collection<int,array<string,mixed>>, fastest_driver:?string, fastest_time:?string}  $data
     */
    private function syncSession(F2Season $season, F2Race $race, F2SessionType $type, array $data, ?string $poleDriver = null): void
    {
        if ($data['results']->isEmpty()) {
            return; // няма данни за тази сесия (напр. бъдещ кръг) — пропусни
        }

        $session = F2RaceSession::query()->updateOrCreate(
            ['f2_race_id' => $race->id, 'session_type' => $type->value],
            [
                'laps' => $data['results']->max('laps'),
                'fastest_lap_driver_id' => $data['fastest_driver'] ? $this->driver($season, $data['fastest_driver'])->id : null,
                'fastest_lap_time' => $data['fastest_time'],
                'pole_position_driver_id' => $poleDriver ? $this->driver($season, $poleDriver)->id : null,
            ],
        );

        $this->stats['sessions']++;

        foreach ($data['results'] as $row) {
            $driver = $this->driver($season, $row['driver'], $row['driver_flag'], $row['car_number'], $row['team']);

            F2Result::query()->updateOrCreate(
                ['f2_race_session_id' => $session->id, 'f2_driver_id' => $driver->id],
                [
                    'position' => $row['position'],
                    'grid_position' => $row['grid'],
                    'laps_completed' => $row['laps'],
                    'time_or_gap' => $row['time_or_gap'],
                    'points' => $row['points'],
                    'status' => $row['status'],
                    'fastest_lap' => $data['fastest_driver'] !== null && $row['driver'] === $data['fastest_driver'],
                ],
            );

            $this->stats['results']++;
        }
    }

    /**
     * Намира/създава F2 пилот (Wikipedia = източник). Обновява номер/отбор/флаг
     * когато са налични.
     */
    private function driver(F2Season $season, string $name, ?string $flag = null, ?int $number = null, ?string $teamName = null): F2Driver
    {
        $slug = Str::slug($name);
        [$first, $last] = $this->splitName($name);

        $team = $teamName ? $this->team($season, $teamName) : null;

        $attrs = ['first_name' => $first, 'last_name' => $last];
        if ($flag !== null) {
            $attrs['country_code'] = $flag;
        }
        if ($number !== null) {
            $attrs['car_number'] = $number;
        }
        if ($team !== null) {
            $attrs['f2_team_id'] = $team->id;
        }

        return F2Driver::query()->updateOrCreate(
            ['f2_season_id' => $season->id, 'slug' => $slug],
            $attrs,
        );
    }

    private function team(F2Season $season, string $name): F2Team
    {
        return F2Team::query()->updateOrCreate(
            ['f2_season_id' => $season->id, 'slug' => Str::slug($name)],
            ['name' => $name],
        );
    }

    /**
     * Класиране: сума точки от всички резултати за сезона → позиция + шампион
     * (само за приключил сезон — не за текущия).
     */
    private function computeStandings(F2Season $season): void
    {
        $totals = F2Result::query()
            ->join('f2_race_sessions', 'f2_race_sessions.id', '=', 'f2_results.f2_race_session_id')
            ->join('f2_races', 'f2_races.id', '=', 'f2_race_sessions.f2_race_id')
            ->where('f2_races.f2_season_id', $season->id)
            ->groupBy('f2_results.f2_driver_id')
            ->selectRaw('f2_results.f2_driver_id as did, SUM(f2_results.points) as pts')
            ->pluck('pts', 'did');

        $ranked = $totals->sortDesc()->keys()->values();

        foreach ($season->drivers as $driver) {
            $rank = $ranked->search($driver->id);
            $driver->update([
                'points' => (float) ($totals[$driver->id] ?? 0),
                'position' => $rank === false ? null : $rank + 1,
                'is_champion' => $rank === 0 && ! $season->is_current,
            ]);
        }
    }

    private function locationFromTitle(string $title, int $year): string
    {
        $loc = preg_replace('/^'.$year.'\s+(.*?)\s+Formula 2 round$/', '$1', $title);

        return $loc === $title ? $title : trim((string) $loc);
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
