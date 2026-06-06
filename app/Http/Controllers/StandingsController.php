<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ConstructorResource;
use App\Http\Resources\DriverResource;
use App\Models\Season;
use App\Services\Standings\StandingsService;
use Inertia\Inertia;
use Inertia\Response;

class StandingsController extends Controller
{
    public function index(StandingsService $standings, ?int $year = null): Response
    {
        $season = $year !== null
            ? Season::query()->where('year', $year)->first()
            : Season::current();

        // Подадена година, която не съществува → 404.
        abort_if($year !== null && $season === null, 404);

        if ($season === null) {
            return Inertia::render('Standings/Index', [
                'season' => null,
                'seasons' => $this->seasonYears(),
                'drivers' => [],
                'constructors' => [],
            ]);
        }

        $drivers = $standings->drivers($season)->map(fn ($row) => [
            'position' => $row['position'],
            'points' => $row['points'],
            'wins' => $row['wins'],
            'driver' => new DriverResource($row['driver']->loadMissing('constructor')),
        ]);

        $constructors = $standings->constructors($season)->map(fn ($row) => [
            'position' => $row['position'],
            'points' => $row['points'],
            'wins' => $row['wins'],
            'constructor' => new ConstructorResource($row['constructor']),
        ]);

        return Inertia::render('Standings/Index', [
            'season' => $season->year,
            'seasons' => $this->seasonYears(),
            'drivers' => $drivers,
            'constructors' => $constructors,
        ]);
    }

    /**
     * Годините със сезони (за dropdown-а), най-новите първо.
     *
     * @return array<int, int>
     */
    private function seasonYears(): array
    {
        return Season::query()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all();
    }
}
