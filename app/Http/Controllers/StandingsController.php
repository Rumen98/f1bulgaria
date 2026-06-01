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
    public function index(StandingsService $standings): Response
    {
        $season = Season::current();

        if ($season === null) {
            return Inertia::render('Standings/Index', [
                'season' => null,
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
            'drivers' => $drivers,
            'constructors' => $constructors,
        ]);
    }
}
