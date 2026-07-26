<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Driver;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Support\CountryFlag;
use App\Support\DriverName;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class F2DriversController extends Controller
{
    public function index(): Response
    {
        $season = F2Season::current();

        $drivers = $season
            ? $season->drivers()->with('team')
                ->orderByRaw('position is null, position')
                ->get()
                ->map(fn (F2Driver $d) => [
                    'slug' => $d->slug,
                    'name' => DriverName::display($d->slug, $d->fullName()),
                    'flag' => CountryFlag::iso2($d->country_code),
                    'team' => $d->team?->name,
                    'position' => $d->position,
                    'points' => $d->points,
                    'is_bulgarian' => $d->country_code === 'BUL',
                ])
            : collect();

        return Inertia::render('F2/DriverIndex', [
            'season' => $season?->year,
            'drivers' => $drivers,
        ]);
    }

    public function show(string $slug): Response
    {
        $entries = F2Driver::query()->where('slug', $slug)->with(['season', 'team'])->get();

        abort_if($entries->isEmpty(), 404);

        $latest = $entries->sortByDesc(fn (F2Driver $d) => $d->season?->year)->first();
        $ids = $entries->pluck('id');

        return Inertia::render('F2/DriverShow', [
            'driver' => [
                'slug' => $slug,
                'name' => DriverName::display($slug, $latest->fullName()),
                'flag' => CountryFlag::iso2($latest->country_code),
                'country_code' => $latest->country_code,
                'car_number' => $latest->car_number,
                'current_team' => $latest->team?->name,
                'is_bulgarian' => $latest->country_code === 'BUL',
            ],
            'stats' => $this->careerStats($entries, $ids),
            'seasons' => $this->seasonBreakdown($entries),
            'recentResults' => $this->recentResults($ids),
        ]);
    }

    /**
     * @param  Collection<int, F2Driver>  $entries
     * @param  Collection<int, int>  $ids
     * @return array<string, int>
     */
    private function careerStats(Collection $entries, Collection $ids): array
    {
        $results = F2Result::query()->whereIn('f2_driver_id', $ids);

        return [
            'starts' => (clone $results)->count(),
            'wins' => (clone $results)->where('position', 1)->count(),
            'podiums' => (clone $results)->whereNotNull('position')->where('position', '<=', 3)->count(),
            'poles' => F2RaceSession::query()->whereIn('pole_position_driver_id', $ids)->count(),
            'championships' => $entries->where('is_champion', true)->count(),
            'seasons' => $entries->count(),
        ];
    }

    /**
     * @param  Collection<int, F2Driver>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function seasonBreakdown(Collection $entries): Collection
    {
        return $entries
            ->sortByDesc(fn (F2Driver $d) => $d->season?->year)
            ->values()
            ->map(fn (F2Driver $d) => [
                'year' => $d->season?->year,
                'team' => $d->team?->name,
                'position' => $d->position,
                'points' => $d->points,
                'is_champion' => $d->is_champion,
            ]);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function recentResults(Collection $ids): Collection
    {
        return F2Result::query()
            ->whereIn('f2_driver_id', $ids)
            ->with(['session.race.season'])
            ->get()
            ->sortByDesc(fn (F2Result $r) => [$r->session?->race?->season?->year ?? 0, $r->session?->race?->round ?? 0])
            ->take(10)
            ->values()
            ->map(fn (F2Result $r) => [
                'year' => $r->session?->race?->season?->year,
                'location' => $r->session?->race?->location_name,
                'session' => $r->session?->session_type?->label(),
                'race_slug' => $r->session?->race?->slug,
                'session_type' => $r->session?->session_type?->value === 'feature_race' ? 'feature' : 'sprint',
                'position' => $r->position,
                'status' => $r->status,
                'points' => (float) $r->points,
            ]);
    }
}
