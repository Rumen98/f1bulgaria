<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;
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
                'poles' => Race::query()->where('season_id', $season->id)->where('pole_driver_id', $driver->id)->count(),
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
                'poles' => Race::query()->whereIn('pole_driver_id', $ids)->count(),
                'races' => $race()->distinct()->count('race_id'),
                'seasons' => Driver::query()->whereIn('id', $ids)->distinct()->count('season_id'),
            ];
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

        $empty = ['teammate' => $teammate?->fullName(), 'race_wins' => 0, 'race_losses' => 0, 'quali_wins' => 0, 'quali_losses' => 0];

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
