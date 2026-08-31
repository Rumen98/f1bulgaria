<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;
use App\Support\DriverName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DriverStatsService
{
    public function __construct(private readonly StandingsService $standings) {}

    /**
     * @return array{position:?int, points:float, wins:int, podiums:int, poles:int, fastest_laps:int, dnfs:int}
     */
    public function getSeasonStats(Driver $driver, Season $season): array
    {
        return Cache::remember("driver-season:{$driver->id}:{$season->id}", now()->addHour(), function () use ($driver, $season) {
            $race = fn () => Result::query()
                ->where('results.driver_id', $driver->id)
                ->where('results.session_type', ResultSessionType::Race->value)
                ->join('races', 'races.id', '=', 'results.race_id')
                ->where('races.season_id', $season->id);

            $row = $this->standings->drivers($season)->firstWhere('driver.id', $driver->id);

            return [
                'position' => $row['position'] ?? null,
                'points' => (float) Result::query()
                    ->where('driver_id', $driver->id)
                    ->whereHas('race', fn ($q) => $q->where('season_id', $season->id))
                    ->sum('points'),
                'wins' => $race()->where('results.position', 1)->count(),
                'podiums' => $race()->whereBetween('results.position', [1, 3])->count(),
                'poles' => $race()->where('results.grid_position', 1)->count(),
                'fastest_laps' => $race()->where('results.fastest_lap', true)->count(),
                'dnfs' => $race()->where('results.dnf', true)->count(),
            ];
        });
    }

    /**
     * All-time статистика чрез driver_code (един пилот = няколко записа по сезони).
     *
     * @return array{points:float, wins:int, podiums:int, poles:int, races:int, seasons:int}
     */
    public function getAllTimeStats(Driver $driver): array
    {
        return Cache::remember("driver-alltime:{$driver->driver_code}:{$driver->id}", now()->addHour(), function () use ($driver) {
            $ids = $this->sameDriverIds($driver);

            $race = fn () => Result::query()
                ->whereIn('driver_id', $ids)
                ->where('session_type', ResultSessionType::Race->value);

            return [
                'points' => (float) Result::query()->whereIn('driver_id', $ids)->sum('points'),
                'wins' => $race()->where('position', 1)->count(),
                'podiums' => $race()->whereBetween('position', [1, 3])->count(),
                'poles' => $race()->where('grid_position', 1)->count(),
                'races' => $race()->distinct()->count('race_id'),
                'seasons' => Driver::query()->whereIn('id', $ids)->distinct()->count('season_id'),
            ];
        });
    }

    /**
     * Резолва каноничен пилот по slug (един запис на човек). null ако липсва.
     */
    public function getCanonicalBySlug(string $slug): ?DriverCanonical
    {
        return DriverCanonical::query()->where('slug', $slug)->first();
    }

    /**
     * All-time статистика за каноничен пилот. Победите/подиумите/полетата/
     * състезанията идват от предварително изчислените колони; точки, сезони и
     * най-бързи обиколки се смятат от свързаните per-season записи. Супермножество,
     * което покрива и „all-time", и „постижения" props на страницата.
     *
     * @return array{points:float, wins:int, podiums:int, poles:int, fastest_laps:int, races:int, seasons:int, win_rate:float}
     */
    public function getStatsForCanonical(DriverCanonical $canonical): array
    {
        return Cache::remember("canon-stats:{$canonical->id}", now()->addDay(), function () use ($canonical) {
            $driverIds = Driver::query()->where('canonical_id', $canonical->id)->pluck('id');

            $points = (float) Result::query()->whereIn('driver_id', $driverIds)->sum('points');
            $seasons = (int) Driver::query()->where('canonical_id', $canonical->id)->distinct()->count('season_id');
            $fastestLaps = Result::query()
                ->whereIn('driver_id', $driverIds)
                ->where('session_type', ResultSessionType::Race->value)
                ->where('fastest_lap', true)
                ->count();

            $races = $canonical->total_races;

            return [
                'points' => $points,
                'wins' => $canonical->total_wins,
                'podiums' => $canonical->total_podiums,
                'poles' => $canonical->total_poles,
                'fastest_laps' => $fastestLaps,
                'races' => $races,
                'seasons' => $seasons,
                'win_rate' => $races > 0 ? round($canonical->total_wins / $races * 100, 1) : 0.0,
            ];
        });
    }

    /**
     * Кариерна хронология по сезони за каноничен пилот (по canonical_id): година,
     * отбор, точки, победи и подиуми за всеки сезон. is_champion е placeholder
     * (шампионските данни не се пазят — изисква стендинг към края на сезона).
     *
     * @return Collection<int, array{year:int, team:?string, color:string, team_slug:?string, points:float, wins:int, podiums:int, is_champion:bool}>
     */
    public function getCareerTimelineForCanonical(DriverCanonical $canonical): Collection
    {
        return Cache::remember("canon-timeline:{$canonical->id}", now()->addDay(), function () use ($canonical) {
            return Result::query()
                ->selectRaw('seasons.year as year, SUM(results.points) as points, '
                    ."SUM(CASE WHEN results.position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as wins, "
                    ."SUM(CASE WHEN results.position BETWEEN 1 AND 3 AND results.session_type = 'race' THEN 1 ELSE 0 END) as podiums, "
                    .'MAX(constructors.name) as team, MAX(constructors.color_hex) as color, MAX(constructors.slug) as team_slug')
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->join('seasons', 'seasons.id', '=', 'drivers.season_id')
                ->leftJoin('constructors', 'constructors.id', '=', 'drivers.constructor_id')
                ->where('drivers.canonical_id', $canonical->id)
                ->groupBy('seasons.year')
                ->orderBy('seasons.year')
                ->get()
                ->map(fn ($r) => [
                    'year' => (int) $r->year,
                    'team' => $r->team,
                    'color' => $r->color ?? '#52525b',
                    'team_slug' => $r->team_slug,
                    'points' => (float) $r->points,
                    'wins' => (int) $r->wins,
                    'podiums' => (int) $r->podiums,
                    'is_champion' => false,
                ]);
        });
    }

    /**
     * Победите на каноничния пилот групирани по писта (jolpica_id), подредени по брой.
     *
     * @return Collection<int, array{circuit_slug:string, circuit:string, wins:int}>
     */
    public function getCircuitWinsForCanonical(DriverCanonical $canonical): Collection
    {
        return Cache::remember("canon-circuit-wins:{$canonical->id}", now()->addDay(), function () use ($canonical) {
            return Result::query()
                ->selectRaw('races.jolpica_id as circuit_slug, MIN(races.circuit) as circuit, COUNT(*) as wins')
                ->join('races', 'races.id', '=', 'results.race_id')
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->where('drivers.canonical_id', $canonical->id)
                ->where('results.session_type', ResultSessionType::Race->value)
                ->where('results.position', 1)
                ->whereNotNull('races.jolpica_id')
                ->groupBy('races.jolpica_id')
                ->orderByDesc('wins')
                ->get()
                ->map(fn ($r) => [
                    'circuit_slug' => $r->circuit_slug,
                    'circuit' => $r->circuit,
                    'wins' => (int) $r->wins,
                ]);
        });
    }

    /**
     * Кариерна хронология по сезони (групирано по driver_code): година, отбор,
     * точки и победи за всеки сезон, в който пилотът е карал.
     *
     * @return Collection<int, array{year:int, team:?string, color:string, points:float, wins:int}>
     */
    public function getCareerTimeline(Driver $driver): Collection
    {
        return Cache::remember("driver-timeline:{$driver->driver_code}:{$driver->id}", now()->addDay(), function () use ($driver) {
            $ids = $this->sameDriverIds($driver);

            return Result::query()
                ->selectRaw('seasons.year as year, SUM(results.points) as points, '
                    ."SUM(CASE WHEN results.position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as wins, "
                    .'MAX(constructors.name) as team, MAX(constructors.color_hex) as color')
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->join('seasons', 'seasons.id', '=', 'drivers.season_id')
                ->leftJoin('constructors', 'constructors.id', '=', 'drivers.constructor_id')
                ->whereIn('results.driver_id', $ids)
                ->groupBy('seasons.year')
                ->orderBy('seasons.year')
                ->get()
                ->map(fn ($r) => [
                    'year' => (int) $r->year,
                    'team' => $r->team,
                    'color' => $r->color ?? '#52525b',
                    'points' => (float) $r->points,
                    'wins' => (int) $r->wins,
                ]);
        });
    }

    /**
     * Индекс на всички пилоти (групирани по код): име, slug, all-time победи,
     * брой сезони и дали са в текущия сезон — за филтрите на /drivers.
     *
     * @return Collection<int, array{code:string, name:string, slug:string, color:string, wins:int, seasons:int, in_current:bool}>
     */
    public function getDriverIndex(): Collection
    {
        return Cache::remember('driver-index', now()->addHour(), function () {
            $current = Season::current();
            $currentCodes = $current
                ? Driver::query()->where('season_id', $current->id)->whereNotNull('driver_code')->pluck('driver_code')->all()
                : [];

            $stats = Result::query()
                ->selectRaw('drivers.driver_code as code, '
                    ."SUM(CASE WHEN results.position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as wins, "
                    .'COUNT(DISTINCT drivers.season_id) as seasons')
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->whereNotNull('drivers.driver_code')
                ->groupBy('drivers.driver_code')
                ->get()
                ->keyBy('code');

            // Последен ред на всеки код (име/slug/цвят) — подреден по season_id, последният печели.
            $latest = [];
            Driver::query()
                ->whereNotNull('driver_code')
                ->orderBy('season_id')
                ->with('constructor')
                ->get(['id', 'season_id', 'driver_code', 'first_name', 'last_name', 'slug', 'constructor_id'])
                ->each(function (Driver $d) use (&$latest) {
                    $latest[$d->driver_code] = $d;
                });

            return collect($latest)->map(function (Driver $d) use ($stats) {
                return [
                    'code' => $d->driver_code,
                    'name' => DriverName::display($d->slug, $d->fullName()),
                    'slug' => $d->slug,
                    'color' => $d->constructor?->color_hex ?? '#52525b',
                    'wins' => (int) ($stats->get($d->driver_code)->wins ?? 0),
                    'seasons' => (int) ($stats->get($d->driver_code)->seasons ?? 0),
                    'in_current' => false,
                ];
            })->map(function (array $row) use ($currentCodes) {
                $row['in_current'] = in_array($row['code'], $currentCodes, true);

                return $row;
            })->sortByDesc('wins')->values();
        });
    }

    /**
     * Кариерни постижения (all-time по driver_code) за секцията „Постижения".
     *
     * @return array{wins:int, podiums:int, poles:int, fastest_laps:int, races:int, win_rate:float}
     */
    public function getAchievements(Driver $driver): array
    {
        return Cache::remember("driver-achievements:{$driver->driver_code}:{$driver->id}", now()->addDay(), function () use ($driver) {
            $ids = $this->sameDriverIds($driver);

            $race = fn () => Result::query()
                ->whereIn('driver_id', $ids)
                ->where('session_type', ResultSessionType::Race->value);

            $races = $race()->distinct()->count('race_id');
            $wins = $race()->where('position', 1)->count();

            return [
                'wins' => $wins,
                'podiums' => $race()->whereBetween('position', [1, 3])->count(),
                'poles' => $race()->where('grid_position', 1)->count(),
                'fastest_laps' => $race()->where('fastest_lap', true)->count(),
                'races' => $races,
                'win_rate' => $races > 0 ? round($wins / $races * 100, 1) : 0.0,
            ];
        });
    }

    /**
     * Победите на пилота групирани по писта (jolpica_id), подредени по брой.
     *
     * @return Collection<int, array{circuit_slug:string, circuit:string, wins:int}>
     */
    public function getCircuitWins(Driver $driver): Collection
    {
        return Cache::remember("driver-circuit-wins:{$driver->driver_code}:{$driver->id}", now()->addDay(), function () use ($driver) {
            $ids = $this->sameDriverIds($driver);

            return Result::query()
                ->selectRaw('races.jolpica_id as circuit_slug, MIN(races.circuit) as circuit, COUNT(*) as wins')
                ->join('races', 'races.id', '=', 'results.race_id')
                ->whereIn('results.driver_id', $ids)
                ->where('results.session_type', ResultSessionType::Race->value)
                ->where('results.position', 1)
                ->whereNotNull('races.jolpica_id')
                ->groupBy('races.jolpica_id')
                ->orderByDesc('wins')
                ->get()
                ->map(fn ($r) => [
                    'circuit_slug' => $r->circuit_slug,
                    'circuit' => $r->circuit,
                    'wins' => (int) $r->wins,
                ]);
        });
    }

    /**
     * Head-to-head срещу съотборника за сезона. Квалификацията се сравнява по
     * стартова позиция (grid_position), тъй като не пазим пълни quali класирания.
     *
     * @return array{teammate:?string, race_wins:int, race_losses:int, quali_wins:int, quali_losses:int}
     */
    public function getHeadToHeadVsTeammate(Driver $driver, Season $season): array
    {
        $teammate = Driver::query()
            ->where('season_id', $season->id)
            ->where('constructor_id', $driver->constructor_id)
            ->whereNotNull('constructor_id')
            ->where('id', '!=', $driver->id)
            ->first();

        $empty = [
            'teammate' => $teammate === null ? null : DriverName::display($teammate->slug, $teammate->fullName()),
            // Slug-ът прави съотборника кликаем в H2H блока.
            'teammate_slug' => $teammate?->slug,
            'race_wins' => 0,
            'race_losses' => 0,
            'quali_wins' => 0,
            'quali_losses' => 0,
        ];

        if ($teammate === null) {
            return $empty;
        }

        $mine = $this->raceRowsByRace($driver);
        $theirs = $this->raceRowsByRace($teammate);

        $stats = $empty;
        foreach ($mine as $raceId => $me) {
            $rival = $theirs->get($raceId);
            if ($rival === null) {
                continue;
            }
            if ($me->position !== null && $rival->position !== null) {
                $me->position < $rival->position ? $stats['race_wins']++ : $stats['race_losses']++;
            }
            if ($me->grid_position !== null && $rival->grid_position !== null) {
                $me->grid_position < $rival->grid_position ? $stats['quali_wins']++ : $stats['quali_losses']++;
            }
        }

        return $stats;
    }

    /**
     * @return Collection<int, int>
     */
    private function sameDriverIds(Driver $driver): Collection
    {
        if (blank($driver->driver_code)) {
            return collect([$driver->id]);
        }

        return Driver::query()->where('driver_code', $driver->driver_code)->pluck('id');
    }

    /**
     * @return Collection<int, Result> индексирани по race_id (само race сесия)
     */
    private function raceRowsByRace(Driver $driver): Collection
    {
        return $driver->results()
            ->where('session_type', ResultSessionType::Race->value)
            ->get()
            ->keyBy('race_id');
    }
}
