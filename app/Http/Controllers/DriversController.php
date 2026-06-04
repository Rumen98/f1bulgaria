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
            'allTime' => $this->stats->getDriverIndex(),
        ]);
    }

    public function show(string $slug): Response
    {
        $current = Season::current();

        // Резолв cross-season: предпочитаме реда от най-новия сезон за този slug
        // (текущ пилот → текущ сезон; легенда → последния му сезон).
        $driver = Driver::query()
            ->where('drivers.slug', $slug)
            ->join('seasons', 'seasons.id', '=', 'drivers.season_id')
            ->orderByDesc('seasons.year')
            ->select('drivers.*')
            ->with(['constructor', 'season'])
            ->first();

        abort_if($driver === null, 404);

        $statsSeason = $driver->season;
        $isHistorical = $current === null || $driver->season_id !== $current->id;

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
                'photo' => $driver->photo_url,
                'flag' => CountryFlag::emoji($driver->country_code),
                'team' => $driver->constructor?->name,
                'team_slug' => $driver->constructor?->slug,
                'color_hex' => $driver->constructor?->color_hex ?? '#e10600',
            ],
            'season' => $statsSeason->year,
            'isHistorical' => $isHistorical,
            'seasonStats' => $this->stats->getSeasonStats($driver, $statsSeason),
            'allTimeStats' => $this->stats->getAllTimeStats($driver),
            'achievements' => $this->stats->getAchievements($driver),
            'circuitWins' => $this->stats->getCircuitWins($driver),
            'careerTimeline' => $this->stats->getCareerTimeline($driver),
            'headToHead' => $this->stats->getHeadToHeadVsTeammate($driver, $statsSeason),
            'recentResults' => $recent,
        ]);
    }
}
