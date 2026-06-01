<?php

declare(strict_types=1);

namespace App\Services\Standings;

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Result;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Изчислява класирането на пилоти и конструктори от синхронизираните резултати.
 */
class StandingsService
{
    /**
     * @return Collection<int, array{position:int, driver:Driver, points:float, wins:int}>
     */
    public function drivers(Season $season): Collection
    {
        $rows = Result::query()
            ->selectRaw('results.driver_id, SUM(results.points) as points, '
                .'SUM(CASE WHEN results.position = 1 THEN 1 ELSE 0 END) as wins')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.season_id', $season->id)
            ->groupBy('results.driver_id')
            ->orderByDesc('points')
            ->orderByDesc('wins')
            ->get();

        $drivers = Driver::query()
            ->with('constructor')
            ->whereIn('id', $rows->pluck('driver_id'))
            ->get()
            ->keyBy('id');

        return $rows->values()->map(fn ($row, $i) => [
            'position' => $i + 1,
            'driver' => $drivers->get($row->driver_id),
            'points' => (float) $row->points,
            'wins' => (int) $row->wins,
        ]);
    }

    /**
     * @return Collection<int, array{position:int, constructor:Constructor, points:float, wins:int}>
     */
    public function constructors(Season $season): Collection
    {
        $rows = Result::query()
            ->selectRaw('drivers.constructor_id, SUM(results.points) as points, '
                .'SUM(CASE WHEN results.position = 1 THEN 1 ELSE 0 END) as wins')
            ->join('drivers', 'drivers.id', '=', 'results.driver_id')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('races.season_id', $season->id)
            ->whereNotNull('drivers.constructor_id')
            ->groupBy('drivers.constructor_id')
            ->orderByDesc('points')
            ->orderByDesc('wins')
            ->get();

        $constructors = Constructor::query()
            ->whereIn('id', $rows->pluck('constructor_id'))
            ->get()
            ->keyBy('id');

        return $rows->values()->map(fn ($row, $i) => [
            'position' => $i + 1,
            'constructor' => $constructors->get($row->constructor_id),
            'points' => (float) $row->points,
            'wins' => (int) $row->wins,
        ]);
    }
}
