<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RaceResource;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Services\Calendar\IcsCalendarBuilder;
use App\Support\DriverName;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CalendarController extends Controller
{
    public function index(): InertiaResponse
    {
        $season = Season::current();

        $races = $season
            ? $season->races()->with(['results', 'sessions'])->orderBy('round')->get()
            : collect();

        return Inertia::render('Races/Calendar', [
            'season' => $season?->year,
            'races' => RaceResource::collection($races),
        ]);
    }

    /**
     * Пълен .ics календар (текущ + следващ сезон), всички сесии.
     */
    public function ics(IcsCalendarBuilder $builder): Response
    {
        return $this->icsResponse(
            $builder->build($this->feedRaces(), 'Падок — Календар'),
            'f1-calendar',
        );
    }

    /**
     * .ics само за състезанията на даден пилот от текущия сезон.
     */
    public function driverIcs(string $slug, IcsCalendarBuilder $builder): Response
    {
        $season = Season::current();
        abort_if($season === null, 404);

        $driver = Driver::query()
            ->where('season_id', $season->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $driverName = DriverName::display($driver->slug, $driver->fullName());

        return $this->icsResponse(
            $builder->build($this->seasonRaces($season), "Падок — Календар на {$driverName}"),
            "f1-{$slug}",
        );
    }

    /**
     * .ics само за състезанията на даден отбор от текущия сезон.
     */
    public function teamIcs(string $slug, IcsCalendarBuilder $builder): Response
    {
        $season = Season::current();
        abort_if($season === null, 404);

        $team = Constructor::query()
            ->where('season_id', $season->id)
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->icsResponse(
            $builder->build($this->seasonRaces($season), "Падок — Календар на {$team->name}"),
            "f1-team-{$slug}",
        );
    }

    /**
     * Състезанията от текущия и следващия сезон (предстоящи).
     *
     * @return Collection<int, Race>
     */
    private function feedRaces(): Collection
    {
        $currentYear = Season::current()?->year ?? (int) Season::query()->max('year');

        return Race::query()
            ->whereHas('season', fn ($q) => $q->whereBetween('year', [$currentYear, $currentYear + 1]))
            ->with(['sessions', 'season'])
            ->orderBy('race_datetime_utc')
            ->get();
    }

    /**
     * @return Collection<int, Race>
     */
    private function seasonRaces(Season $season): Collection
    {
        return $season->races()->with('sessions')->orderBy('round')->get();
    }

    private function icsResponse(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "inline; filename=\"{$filename}.ics\"",
        ]);
    }
}
