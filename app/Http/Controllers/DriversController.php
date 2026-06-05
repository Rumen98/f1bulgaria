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
        // Идентичността идва от каноничния модел (един запис на човек).
        $canonical = $this->stats->getCanonicalBySlug($slug);

        abort_if($canonical === null, 404);

        // Най-новият per-season ред на този човек — за hero отбора и за
        // сезонно-специфичните секции (head-to-head, последни резултати).
        $driver = Driver::query()
            ->where('drivers.canonical_id', $canonical->id)
            ->join('seasons', 'seasons.id', '=', 'drivers.season_id')
            ->orderByDesc('seasons.year')
            ->select('drivers.*')
            ->with(['constructor', 'season'])
            ->first();

        $statsSeason = $driver?->season;

        $recent = $driver
            ? $driver->results()
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
                ])->values()
            : collect();

        return Inertia::render('Drivers/Show', [
            'driver' => [
                'name' => $canonical->fullName(),
                'number' => $canonical->permanent_number ?? $driver?->permanent_number,
                'code' => $canonical->code,
                'photo' => $canonical->photo_url ?? $driver?->photo_url,
                'flag' => CountryFlag::emoji($canonical->country_code),
                'team' => $driver?->constructor?->name,
                'team_slug' => $driver?->constructor?->slug,
                'color_hex' => $driver?->constructor?->color_hex ?? '#e10600',
            ],
            'season' => $statsSeason?->year,
            'isHistorical' => ! $canonical->is_active,
            'seasonStats' => $driver && $statsSeason ? $this->stats->getSeasonStats($driver, $statsSeason) : null,
            'allTimeStats' => $this->stats->getStatsForCanonical($canonical),
            'achievements' => $this->stats->getStatsForCanonical($canonical),
            'circuitWins' => $this->stats->getCircuitWinsForCanonical($canonical),
            'careerTimeline' => $this->stats->getCareerTimelineForCanonical($canonical),
            'headToHead' => $driver && $statsSeason
                ? $this->stats->getHeadToHeadVsTeammate($driver, $statsSeason)
                : ['teammate' => null, 'race_wins' => 0, 'race_losses' => 0, 'quali_wins' => 0, 'quali_losses' => 0],
            'recentResults' => $recent,
        ]);
    }
}
