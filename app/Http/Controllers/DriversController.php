<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ResultSessionType;
use App\Models\Driver;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\DriverStatsService;
use App\Services\Standings\StandingsService;
use App\Support\CountryFlag;
use Inertia\Inertia;
use Inertia\Response;

class DriversController extends Controller
{
    public function __construct(
        private readonly DriverStatsService $stats,
        private readonly StandingsService $standings,
    ) {}

    public function index(): Response
    {
        $season = Season::current();

        if ($season === null) {
            return Inertia::render('Drivers/Index', ['season' => null, 'drivers' => []]);
        }

        $standings = $this->standings->drivers($season)->keyBy(fn ($r) => $r['driver']->id);

        $drivers = $season->drivers()->with('constructor')->get()
            ->map(fn (Driver $d) => [
                'slug' => $d->slug,
                'name' => $d->fullName(),
                'code' => $d->driver_code,
                'number' => $d->permanent_number,
                'flag' => CountryFlag::emoji($d->country_code),
                'team' => $d->constructor?->name,
                'color_hex' => $d->constructor?->color_hex ?? '#e10600',
                'position' => $standings->get($d->id)['position'] ?? null,
                'points' => $standings->get($d->id)['points'] ?? 0.0,
            ])
            ->sortBy(fn ($d) => $d['position'] ?? 999)
            ->values();

        return Inertia::render('Drivers/Index', [
            'season' => $season->year,
            'drivers' => $drivers,
        ]);
    }

    public function show(string $slug): Response
    {
        $season = Season::current();
        abort_if($season === null, 404);

        $driver = Driver::query()
            ->where('season_id', $season->id)
            ->where('slug', $slug)
            ->with('constructor')
            ->firstOrFail();

        $recent = $driver->results()
            ->where('session_type', ResultSessionType::Race->value)
            ->with('race')
            ->get()
            ->sortByDesc(fn (Result $r) => $r->race?->race_datetime_utc)
            ->take(10)
            ->map(fn (Result $r) => [
                'race' => $r->race?->name,
                'position' => $r->position,
                'points' => (float) $r->points,
                'fastest_lap' => $r->fastest_lap,
            ])->values();

        return Inertia::render('Drivers/Show', [
            'driver' => [
                'name' => $driver->fullName(),
                'number' => $driver->permanent_number,
                'code' => $driver->driver_code,
                'flag' => CountryFlag::emoji($driver->country_code),
                'team' => $driver->constructor?->name,
                'team_slug' => $driver->constructor?->slug,
                'color_hex' => $driver->constructor?->color_hex ?? '#e10600',
            ],
            'season' => $season->year,
            'seasonStats' => $this->stats->getSeasonStats($driver, $season),
            'allTimeStats' => $this->stats->getAllTimeStats($driver),
            'headToHead' => $this->stats->getHeadToHeadVsTeammate($driver, $season),
            'recentResults' => $recent,
        ]);
    }
}
