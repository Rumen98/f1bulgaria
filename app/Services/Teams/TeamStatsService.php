<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\ResultSessionType;
use App\Models\Constructor;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;
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
            poles: Race::query()
                ->where('season_id', $season->id)
                ->whereIn('pole_driver_id', $driverIds)
                ->count(),
            fastestLaps: $race()->where('results.fastest_lap', true)->count(),
            dnfs: $race()->where('results.dnf', true)->count(),
        );
    }
}
