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
use Illuminate\Support\Facades\DB;

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
                    ."SUM(CASE WHEN results.position = 1 AND results.session_type = 'race' THEN 1 ELSE 0 END) as wins")
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->join('drivers_canonical as dc', 'dc.id', '=', 'drivers.canonical_id')
                ->join('races', 'races.id', '=', 'results.race_id')
                ->where('races.jolpica_id', $circuitSlug)
                ->whereNotNull('drivers.canonical_id')
                ->groupBy('drivers.canonical_id', 'dc.code', 'dc.first_name', 'dc.last_name', 'dc.slug')
                ->get();

            $poles = $this->polesByCanonical($circuitSlug);

            return $rows
                ->map(fn ($r) => [
                    'code' => $r->code,
                    'name' => trim("{$r->first_name} {$r->last_name}"),
                    'slug' => $r->slug,
                    'races' => (int) $r->races,
                    'wins' => (int) $r->wins,
                    'poles' => (int) ($poles[$r->cid] ?? 0),
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
     * Pole позиции по canonical_id за дадена писта.
     *
     * @return array<int, int>
     */
    private function polesByCanonical(string $circuitSlug): array
    {
        return DB::table('races')
            ->selectRaw('drivers.canonical_id as cid, COUNT(*) as cnt')
            ->join('drivers', 'drivers.id', '=', 'races.pole_driver_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->whereNotNull('drivers.canonical_id')
            ->groupBy('drivers.canonical_id')
            ->pluck('cnt', 'cid')
            ->map(fn ($c) => (int) $c)
            ->all();
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
     * @return array{most_wins:?array{name:string, count:int}, most_poles:?array{name:string, count:int}, most_fastest_laps:?array{name:string, count:int}}
     */
    public function getRecords(string $circuitSlug): array
    {
        return [
            'most_wins' => $this->topByRace($circuitSlug, fn ($q) => $q->where('results.position', 1)->where('results.session_type', ResultSessionType::Race->value)),
            'most_fastest_laps' => $this->topByRace($circuitSlug, fn ($q) => $q->where('results.fastest_lap', true)),
            'most_poles' => $this->getMostPolePosDriver($circuitSlug),
        ];
    }

    /**
     * Пилотът с най-много pole позиции на тази писта (групирано по driver_code).
     *
     * @return array{name:string, count:int}|null
     */
    public function getMostPolePosDriver(string $circuitSlug): ?array
    {
        $row = DB::table('races')
            ->selectRaw('drivers.driver_code as code, COUNT(*) as cnt')
            ->join('drivers', 'drivers.id', '=', 'races.pole_driver_id')
            ->where('races.jolpica_id', $circuitSlug)
            ->whereNotNull('drivers.driver_code')
            ->groupBy('drivers.driver_code')
            ->orderByDesc('cnt')
            ->first();

        if ($row === null) {
            return null;
        }

        return ['name' => $this->latestNamesByCode(collect([$row->code]))[$row->code] ?? $row->code, 'count' => (int) $row->cnt];
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
