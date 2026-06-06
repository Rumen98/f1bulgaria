<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\ResultSessionType;
use App\Models\Constructor;
use App\Models\ConstructorCanonical;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TeamStatsService
{
    public function __construct(private readonly StandingsService $standings) {}

    public function getSeasonStats(Constructor $constructor, Season $season): TeamSeasonStats
    {
        return Cache::remember(
            "team-stats:{$constructor->id}:{$season->id}",
            now()->addHour(),
            fn () => $this->compute($constructor, $season),
        );
    }

    /**
     * Резолва каноничен конструктор по slug (един запис на отбор). null ако липсва.
     */
    public function getCanonicalBySlug(string $slug): ?ConstructorCanonical
    {
        return ConstructorCanonical::query()->where('slug', $slug)->first();
    }

    /**
     * All-time статистика за каноничен конструктор. Победите/подиумите/полетата/
     * състезанията идват от предварително изчислените колони; точки, най-бързи
     * обиколки, отпадания и брой сезони се смятат от свързаните per-season записи.
     * position е null (няма смисъл all-time).
     *
     * Без all-time точки — точковите системи са се менили през годините и
     * сравнението е подвеждащо. Вместо това показваме титли (championships_count,
     * ръчно поддържано).
     *
     * @return array{position:null, championships:int, wins:int, podiums:int, poles:int, fastest_laps:int, dnfs:int, races:int, seasons:int, win_rate:float}
     */
    public function getStatsForCanonical(ConstructorCanonical $canonical): array
    {
        // Фингърпринт по данните — обезсилва кеша при промяна (нов backfill /
        // ръчно въведени титли) и пази от преплитане между изолирани тестове.
        $fp = "{$canonical->total_races}-{$canonical->total_wins}-{$canonical->championships_count}";

        return Cache::remember("team-canon-stats:{$canonical->id}:{$fp}", now()->addDay(), function () use ($canonical) {
            $constructorIds = Constructor::query()->where('canonical_id', $canonical->id)->pluck('id');

            $agg = Result::query()
                ->join('drivers', 'drivers.id', '=', 'results.driver_id')
                ->whereIn('drivers.constructor_id', $constructorIds)
                ->selectRaw("SUM(CASE WHEN results.session_type = 'race' AND results.fastest_lap = 1 THEN 1 ELSE 0 END) as fl, "
                    ."SUM(CASE WHEN results.session_type = 'race' AND results.dnf = 1 THEN 1 ELSE 0 END) as dnfs")
                ->first();

            $seasons = (int) Constructor::query()->where('canonical_id', $canonical->id)->distinct()->count('season_id');
            $races = $canonical->total_races;

            return [
                'position' => null,
                'championships' => $canonical->championships_count,
                'wins' => $canonical->total_wins,
                'podiums' => $canonical->total_podiums,
                'poles' => $canonical->total_poles,
                'fastest_laps' => (int) ($agg->fl ?? 0),
                'dnfs' => (int) ($agg->dnfs ?? 0),
                'races' => $races,
                'seasons' => $seasons,
                'win_rate' => $races > 0 ? round($canonical->total_wins / $races * 100, 1) : 0.0,
            ];
        });
    }

    /**
     * Индекс на всички канонични отбори (за филтрите на /teams): име, slug, цвят,
     * all-time победи/полета, брой сезони и дали са активни в текущия сезон.
     *
     * @return Collection<int, array{slug:string, name:string, color_hex:string, wins:int, poles:int, seasons:int, is_active:bool}>
     */
    public function getTeamIndex(): Collection
    {
        return Cache::remember('team-index', now()->addHour(), function () {
            $seasonCounts = Constructor::query()
                ->whereNotNull('canonical_id')
                ->selectRaw('canonical_id, COUNT(DISTINCT season_id) as c')
                ->groupBy('canonical_id')
                ->pluck('c', 'canonical_id');

            return ConstructorCanonical::query()
                ->orderByDesc('total_wins')
                ->orderByDesc('total_poles')
                ->get()
                ->map(fn (ConstructorCanonical $c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'name_bg' => config("team-brands.{$c->slug}.name_bg"),
                    'color_hex' => $c->color_hex ?? '#e10600',
                    'wins' => $c->total_wins,
                    'poles' => $c->total_poles,
                    'seasons' => (int) ($seasonCounts[$c->id] ?? 0),
                    'is_active' => $c->is_active,
                ])->values();
        });
    }

    private function compute(Constructor $constructor, Season $season): TeamSeasonStats
    {
        $driverIds = $constructor->drivers()->pluck('id');

        // Базова заявка: резултати на пилотите от отбора в това състезание/сезон.
        $base = fn () => Result::query()
            ->whereIn('results.driver_id', $driverIds)
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.season_id', $season->id);

        $race = fn () => $base()->where('results.session_type', ResultSessionType::Race->value);

        $standingsRow = $this->standings->constructors($season)
            ->firstWhere('constructor.id', $constructor->id);
        $position = $standingsRow['position'] ?? null;

        return new TeamSeasonStats(
            position: $position,
            points: (float) $base()->sum('results.points'),
            wins: $race()->where('results.position', 1)->count(),
            podiums: $race()->whereBetween('results.position', [1, 3])->count(),
            poles: $race()->where('results.grid_position', 1)->count(),
            fastestLaps: $race()->where('results.fastest_lap', true)->count(),
            dnfs: $race()->where('results.dnf', true)->count(),
        );
    }
}
