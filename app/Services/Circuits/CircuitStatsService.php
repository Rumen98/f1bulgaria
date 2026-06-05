<?php

declare(strict_types=1);

namespace App\Services\Circuits;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CircuitStatsService
{
    /**
     * Пистата е „активна", ако има състезание в някой от последните `$yearsBack`
     * сезона (по подразбиране 3 — текущ + 2 назад).
     */
    public function isCircuitActive(string $circuitSlug, int $yearsBack = 3): bool
    {
        $currentYear = Season::current()?->year ?? (int) Season::query()->max('year');

        return Race::query()
            ->where('jolpica_id', $circuitSlug)
            ->whereHas('season', fn ($q) => $q->where('year', '>=', $currentYear - $yearsBack + 1))
            ->exists();
    }

    /**
     * All-time класиране на пилотите за дадена писта — групирано по canonical_id
     * (истинската идентичност на пилота, устойчиво на преизползвани/split кодове).
     *
     * @return Collection<int, array{position:int, code:?string, name:string, slug:?string, races:int, wins:int, poles:int}>
     */
    public function getAllTimeDriverStandings(string $circuitSlug): Collection
    {
        return Cache::remember("circuit-standings:{$circuitSlug}", now()->addDay(), function () use ($circuitSlug) {
            $rows = Result::query()
                ->selectRaw('drivers.canonical_id as cid, dc.code as code, dc.first_name, dc.last_name, dc.slug, '
                    .'COUNT(DISTINCT results.race_id) as races, '
                    ."SUM(CASE WHEN results.position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as wins, "
                    ."SUM(CASE WHEN results.grid_position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as poles")
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->join('drivers_canonical as dc', 'dc.id', '=', 'drivers.canonical_id')
                ->join('races', 'races.id', '=', 'results.race_id')
                ->where('races.jolpica_id', $circuitSlug)
                ->whereNotNull('drivers.canonical_id')
                ->groupBy('drivers.canonical_id', 'dc.code', 'dc.first_name', 'dc.last_name', 'dc.slug')
                ->get();

            return $rows
                ->map(fn ($r) => [
                    'code' => $r->code,
                    'name' => trim("{$r->first_name} {$r->last_name}"),
                    'slug' => $r->slug,
                    'races' => (int) $r->races,
                    'wins' => (int) $r->wins,
                    'poles' => (int) $r->poles,
                ])
                // Победи (primary) → pole → старта. Точките са подвеждащи между
                // ерите (9т за победа до 1990 vs 25т сега), затова не ги ползваме.
                ->sortByDesc(fn ($r) => sprintf('%04d%04d%04d', $r['wins'], $r['poles'], $r['races']))
                ->take(20)
                ->values()
                ->map(fn ($r, $i) => ['position' => $i + 1, ...$r]);
        });
    }

    /**
     * @return Collection<int, array{year:int, driver:string, team:?string, color:?string}>
     */
    public function getLastWinners(string $circuitSlug, int $limit = 10): Collection
    {
        return Result::query()
            ->where('results.position', 1)
            ->where('results.session_type', ResultSessionType::Race->value)
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->with(['driver.constructor', 'race.season'])
            ->orderByDesc('races.race_datetime_utc')
            ->limit($limit)
            ->get(['results.*'])
            ->map(fn (Result $r) => [
                'year' => $r->race?->season?->year,
                'driver' => $r->driver?->fullName(),
                'team' => $r->driver?->constructor?->name,
                'color' => $r->driver?->constructor?->color_hex,
            ]);
    }

    /**
     * Изчерпателни рекорди за пистата.
     *
     * Забележка: `fastest_lap_record` (най-бързо време на обиколка) е пропуснато —
     * в `results` няма колона за време на обиколка, само boolean `fastest_lap`.
     *
     * @return array{
     *     most_wins:?array{name:string, count:int},
     *     most_poles:?array{name:string, count:int},
     *     most_fastest_laps:?array{name:string, count:int},
     *     longest_winning_streak:?array{name:string, count:int},
     *     first_winner:?array{year:int, driver:string},
     *     latest_winner:?array{year:int, driver:string},
     *     pole_to_win_conversion_rate:?float,
     *     avg_winner_starting_position:?float
     * }
     */
    public function getRecords(string $circuitSlug): array
    {
        $winners = $this->raceWinners($circuitSlug);

        return [
            'most_wins' => $this->topByRace($circuitSlug, fn ($q) => $q->where('results.position', 1)->where('results.session_type', ResultSessionType::Race->value)),
            'most_fastest_laps' => $this->topByRace($circuitSlug, fn ($q) => $q->where('results.fastest_lap', true)),
            'most_poles' => $this->getMostPolePosDriver($circuitSlug),
            'longest_winning_streak' => $this->longestWinningStreak($winners),
            'first_winner' => $this->edgeWinner($winners->first()),
            'latest_winner' => $this->edgeWinner($winners->last()),
            'pole_to_win_conversion_rate' => $this->poleToWinRate($winners),
            'avg_winner_starting_position' => $this->avgWinnerGrid($winners),
        ];
    }

    /**
     * Победителите в състезанията на пистата, хронологично (стар → нов), всеки с
     * година, canonical_id, име и стартова позиция. Основа за рекордите по-долу.
     *
     * @return Collection<int, object{year:int, cid:int, name:string, grid:?int}>
     */
    private function raceWinners(string $circuitSlug): Collection
    {
        return Result::query()
            ->selectRaw('seasons.year as year, drivers.canonical_id as cid, dc.first_name, dc.last_name, results.grid_position as grid')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('drivers_canonical as dc', 'dc.id', '=', 'drivers.canonical_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->join('seasons', 'seasons.id', '=', 'races.season_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->where('results.position', 1)
            ->where('results.session_type', ResultSessionType::Race->value)
            ->orderBy('races.race_datetime_utc')
            ->get()
            ->map(fn ($r) => (object) [
                'year' => (int) $r->year,
                'cid' => (int) $r->cid,
                'name' => trim("{$r->first_name} {$r->last_name}"),
                'grid' => $r->grid !== null ? (int) $r->grid : null,
            ]);
    }

    /**
     * Най-дългата серия от последователни победи на един и същ пилот на пистата.
     *
     * @param  Collection<int, object>  $winners
     * @return array{name:string, count:int}|null
     */
    private function longestWinningStreak(Collection $winners): ?array
    {
        if ($winners->isEmpty()) {
            return null;
        }

        $bestName = '';
        $best = 0;
        $curCid = null;
        $curName = '';
        $cur = 0;

        foreach ($winners as $w) {
            if ($w->cid === $curCid) {
                $cur++;
            } else {
                $curCid = $w->cid;
                $curName = $w->name;
                $cur = 1;
            }

            if ($cur > $best) {
                $best = $cur;
                $bestName = $curName;
            }
        }

        return ['name' => $bestName, 'count' => $best];
    }

    /**
     * @return array{year:int, driver:string}|null
     */
    private function edgeWinner(?object $winner): ?array
    {
        return $winner === null ? null : ['year' => $winner->year, 'driver' => $winner->name];
    }

    /**
     * Процент състезания, спечелени от pole (стартова позиция 1) — измежду тези
     * с известна стартова позиция на победителя.
     *
     * @param  Collection<int, object>  $winners
     */
    private function poleToWinRate(Collection $winners): ?float
    {
        $known = $winners->filter(fn ($w) => $w->grid !== null);

        if ($known->isEmpty()) {
            return null;
        }

        $fromPole = $known->filter(fn ($w) => $w->grid === 1)->count();

        return round($fromPole / $known->count() * 100, 1);
    }

    /**
     * Средна стартова позиция на победителите на пистата.
     *
     * @param  Collection<int, object>  $winners
     */
    private function avgWinnerGrid(Collection $winners): ?float
    {
        $grids = $winners->filter(fn ($w) => $w->grid !== null)->map(fn ($w) => $w->grid);

        if ($grids->isEmpty()) {
            return null;
        }

        return round($grids->avg(), 1);
    }

    /**
     * Пилотът с най-много pole позиции на тази писта — групирано по canonical_id,
     * pole = старт от 1-ва позиция (results.grid_position=1).
     *
     * @return array{name:string, count:int}|null
     */
    public function getMostPolePosDriver(string $circuitSlug): ?array
    {
        $row = Result::query()
            ->selectRaw('dc.first_name, dc.last_name, COUNT(*) as cnt')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('drivers_canonical as dc', 'dc.id', '=', 'drivers.canonical_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->where('results.grid_position', 1)
            ->where('results.session_type', ResultSessionType::Race->value)
            ->groupBy('drivers.canonical_id', 'dc.first_name', 'dc.last_name')
            ->orderByDesc('cnt')
            ->first();

        if ($row === null) {
            return null;
        }

        return ['name' => trim("{$row->first_name} {$row->last_name}"), 'count' => (int) $row->cnt];
    }

    /**
     * @return array{race:string, year:?int, top5:Collection<int, array<string,mixed>>}|null
     */
    public function getLastRace(string $circuitSlug): ?array
    {
        $race = Race::query()
            ->where('jolpica_id', $circuitSlug)
            ->whereHas('results')
            ->with('season')
            ->orderByDesc('race_datetime_utc')
            ->first();

        if ($race === null) {
            return null;
        }

        $top5 = $race->results()
            ->where('session_type', ResultSessionType::Race->value)
            ->whereNotNull('position')
            ->with('driver.constructor')
            ->orderBy('position')
            ->limit(5)
            ->get()
            ->map(fn (Result $r) => [
                'position' => $r->position,
                'driver' => $r->driver?->fullName(),
                'team' => $r->driver?->constructor?->name,
                'color' => $r->driver?->constructor?->color_hex,
            ]);

        return ['race' => $race->name, 'year' => $race->season?->year, 'top5' => $top5];
    }

    /**
     * @param  Collection<int, string>  $codes
     * @return array<string, string>
     */
    private function latestNamesByCode(Collection $codes): array
    {
        return Driver::query()
            ->whereIn('driver_code', $codes)
            ->orderBy('season_id')
            ->get()
            ->mapWithKeys(fn (Driver $d) => [$d->driver_code => $d->fullName()])
            ->all();
    }

    /**
     * @return array{name:string, count:int}|null
     */
    private function topByRace(string $circuitSlug, callable $filter): ?array
    {
        $query = Result::query()
            ->selectRaw('drivers.driver_code as code, COUNT(*) as cnt')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->whereNotNull('drivers.driver_code')
            ->groupBy('drivers.driver_code')
            ->orderByDesc('cnt');

        $row = $filter($query)->first();

        if ($row === null || (int) $row->cnt === 0) {
            return null;
        }

        return ['name' => $this->latestNamesByCode(collect([$row->code]))[$row->code] ?? $row->code, 'count' => (int) $row->cnt];
    }
}
