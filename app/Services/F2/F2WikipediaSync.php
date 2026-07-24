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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Синхронизира F2 сезон от Wikipedia (season page → round pages → results).
 * Wikipedia е източникът на истината: пилоти/отбори се създават при нужда.
 * Идемпотентно (updateOrCreate). Без измисляне на данни — липсва → се пропуска.
 */
class F2WikipediaSync
{
    /**
     * Базови точки по позиция в главното състезание (формат от 2022 г. насам).
     * При синхрон на сезони преди 2022 г. схемата е друга — преразгледай.
     *
     * @var array<int, float>
     */
    private const FEATURE_POINTS = [1 => 25.0, 2 => 18.0, 3 => 15.0, 4 => 12.0, 5 => 10.0, 6 => 8.0, 7 => 6.0, 8 => 4.0, 9 => 2.0, 10 => 1.0];

    /** Точки за пол позиция в главното състезание (формат от 2022 г. насам). */
    private const POLE_POINTS = 2.0;

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

        // Текущ сезон = най-новата година; гарантираме точно един current.
        $isCurrent = $year >= (int) (F2Season::query()->max('year') ?? $year);
        $season = F2Season::query()->updateOrCreate(['year' => $year], ['is_current' => $isCurrent]);

        if ($isCurrent) {
            F2Season::query()->where('id', '!=', $season->id)->update(['is_current' => false]);
        }

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

        $featureDate = $this->resolveDate($parsed['feature']['date'] ?? null, $season->year);
        $sprintDate = $this->resolveDate($parsed['sprint']['date'] ?? null, $season->year);

        $race = F2Race::query()->firstOrNew(['f2_season_id' => $season->id, 'round' => $roundNo]);
        $isRelocated = $race->exists && $race->location_name !== $location;

        $race->fill([
            'location_name' => $location,
            'circuit_jolpica_id' => config("f2-circuit-map.{$location}"),
            'slug' => Str::slug("{$season->year}-{$location}"),
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title),
            'race_datetime_utc' => $featureDate ?? $sprintDate,
        ])->save();

        // Кръг с номер, който вече сочи ДРУГО събитие (пренареден календар) —
        // старите сесии/резултати са от предишното и биха се броили двойно.
        if ($isRelocated) {
            $sessionIds = $race->sessions()->pluck('id');
            F2Result::query()->whereIn('f2_race_session_id', $sessionIds)->delete();
            $race->sessions()->delete();
        }

        $this->stats['races']++;

        $this->syncSession($season, $race, F2SessionType::SprintRace, $parsed['sprint']);
        $this->syncSession($season, $race, F2SessionType::FeatureRace, $parsed['feature'], $parsed['pole_driver']);
    }

    /**
     * @param  array{results:Collection<int,array<string,mixed>>, fastest_driver:?string, fastest_time:?string, date:?string}  $data
     */
    private function syncSession(F2Season $season, F2Race $race, F2SessionType $type, array $data, ?string $poleDriver = null): void
    {
        if ($data['results']->isEmpty()) {
            return; // няма данни за тази сесия (напр. бъдещ кръг) — пропусни
        }

        $session = F2RaceSession::query()->updateOrCreate(
            ['f2_race_id' => $race->id, 'session_type' => $type->value],
            [
                'date' => $this->resolveDate($data['date'] ?? null, $season->year),
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
     * Класиране: сума точки от всички резултати за сезона + недостигащите
     * пол бонуси → позиция + шампион (само за приключил сезон — не за текущия).
     */
    private function computeStandings(F2Season $season): void
    {
        $totals = F2Result::query()
            ->join('f2_race_sessions', 'f2_race_sessions.id', '=', 'f2_results.f2_race_session_id')
            ->join('f2_races', 'f2_races.id', '=', 'f2_race_sessions.f2_race_id')
            ->where('f2_races.f2_season_id', $season->id)
            ->groupBy('f2_results.f2_driver_id')
            ->selectRaw('f2_results.f2_driver_id as did, SUM(f2_results.points) as pts')
            ->pluck('pts', 'did')
            ->map(fn ($pts) => (float) $pts);

        foreach ($this->missingPoleBonuses($season) as $driverId => $bonus) {
            $totals->put($driverId, ($totals->get($driverId) ?? 0.0) + $bonus);
        }

        $ranked = $this->rankDrivers($season, $totals);

        foreach ($season->drivers as $driver) {
            $rank = $ranked->search($driver->id);
            $driver->update([
                'points' => $totals->get($driver->id, 0.0),
                'position' => $rank === false ? null : $rank + 1,
                'is_champion' => $rank === 0 && ! $season->is_current,
            ]);
        }
    }

    /**
     * Ранжира пилотите: точки, а при равенство — FIA countback (повече победи,
     * после повече 2-ри места и т.н., по всички състезания в сезона).
     *
     * @param  Collection<int, float>  $totals  driver_id => точки
     * @return Collection<int, int> driver_id в ред на класиране
     */
    private function rankDrivers(F2Season $season, Collection $totals): Collection
    {
        $positionCounts = F2Result::query()
            ->join('f2_race_sessions', 'f2_race_sessions.id', '=', 'f2_results.f2_race_session_id')
            ->join('f2_races', 'f2_races.id', '=', 'f2_race_sessions.f2_race_id')
            ->where('f2_races.f2_season_id', $season->id)
            ->whereNotNull('f2_results.position')
            ->selectRaw('f2_results.f2_driver_id as did, f2_results.position as pos, COUNT(*) as cnt')
            ->groupBy('did', 'pos')
            ->get()
            ->groupBy('did')
            ->map(fn (Collection $rows) => $rows->pluck('cnt', 'pos'));

        return $totals->keys()
            ->sort(function (int $a, int $b) use ($totals, $positionCounts): int {
                $byPoints = $totals[$b] <=> $totals[$a];

                if ($byPoints !== 0) {
                    return $byPoints;
                }

                $countsA = $positionCounts->get($a) ?? collect();
                $countsB = $positionCounts->get($b) ?? collect();
                $worst = (int) max($countsA->keys()->max() ?? 0, $countsB->keys()->max() ?? 0);

                for ($pos = 1; $pos <= $worst; $pos++) {
                    $byCount = ((int) ($countsB->get($pos) ?? 0)) <=> ((int) ($countsA->get($pos) ?? 0));

                    if ($byCount !== 0) {
                        return $byCount;
                    }
                }

                return 0;
            })
            ->values();
    }

    /**
     * Wikipedia непоследователно вгражда 2-те точки за пол в колоната Points
     * на главното състезание (напр. Спа 2026: „25+2+1", но Барселона 2026:
     * само „25"). Затова сверяваме точките на полмена с базовите за позицията
     * му (+1 за най-бърза обиколка в топ 10) — липсва ли бонусът, връщаме го
     * тук, за да е класирането равно на официалното.
     *
     * @return array<int, float> driver_id => недостигащи точки
     */
    private function missingPoleBonuses(F2Season $season): array
    {
        $sessions = F2RaceSession::query()
            ->join('f2_races', 'f2_races.id', '=', 'f2_race_sessions.f2_race_id')
            ->where('f2_races.f2_season_id', $season->id)
            ->where('f2_race_sessions.session_type', F2SessionType::FeatureRace->value)
            ->whereNotNull('f2_race_sessions.pole_position_driver_id')
            ->select('f2_race_sessions.*')
            ->get();

        $bonuses = [];

        foreach ($sessions as $session) {
            $result = F2Result::query()
                ->where('f2_race_session_id', $session->id)
                ->where('f2_driver_id', $session->pole_position_driver_id)
                ->first();

            $position = $result?->position;
            $base = $position !== null ? (self::FEATURE_POINTS[$position] ?? 0.0) : 0.0;
            $fastestLapPoint = ($result?->fastest_lap && $position !== null && $position <= 10) ? 1.0 : 0.0;
            $embedded = (float) ($result?->points ?? 0.0) - $base - $fastestLapPoint;

            if ($embedded < self::POLE_POINTS) {
                $driverId = $session->pole_position_driver_id;
                $bonuses[$driverId] = ($bonuses[$driverId] ?? 0.0) + self::POLE_POINTS;
            }
        }

        return $bonuses;
    }

    /**
     * Дата от Wikipedia (напр. „8 March“) + сезонна година → Carbon. null при
     * липса/непарсваема стойност (бъдещ кръг) — без измисляне.
     */
    private function resolveDate(?string $raw, int $year): ?Carbon
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (! preg_match('/\d{4}/', $raw)) {
            $raw .= ' '.$year;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
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
