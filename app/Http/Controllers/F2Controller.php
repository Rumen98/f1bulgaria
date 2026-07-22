<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Driver;
use App\Models\F2Season;
use App\Support\CountryFlag;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class F2Controller extends Controller
{
    public function index(): Response
    {
        return $this->render(F2Season::current());
    }

    public function season(int $year): Response
    {
        $season = F2Season::query()->where('year', $year)->first();
        abort_if($season === null, 404);

        return $this->render($season);
    }

    private function render(?F2Season $season): Response
    {
        return Inertia::render('F2/Index', [
            'season' => $season?->year,
            'seasons' => F2Season::query()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y),
            'standings' => $season ? $this->standings($season) : collect(),
            'champions' => $this->champions(),
            'bulgarianSpotlight' => $season ? $this->bulgarianSpotlight($season) : null,
        ]);
    }

    /**
     * Български състезател в сезона (за spotlight widget). null ако няма.
     *
     * @return array<string, mixed>|null
     */
    private function bulgarianSpotlight(F2Season $season): ?array
    {
        $driver = $season->drivers()->where('country_code', 'BUL')->with('team')->first();

        if ($driver === null) {
            return null;
        }

        return [
            'slug' => $driver->slug,
            'name' => $driver->fullName(),
            'flag' => CountryFlag::iso2($driver->country_code),
            'team' => $driver->team?->name,
            'position' => $driver->position,
            'points' => $driver->points,
        ];
    }

    /**
     * Класиране за сезона — по позиция (шампионът най-горе, null позиции накрая).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function standings(F2Season $season): Collection
    {
        return $season->drivers()
            ->with('team')
            ->orderByRaw('position is null, position')
            ->orderByDesc('points')
            ->get()
            ->map(fn (F2Driver $d) => [
                'slug' => $d->slug,
                'name' => $d->fullName(),
                'flag' => CountryFlag::iso2($d->country_code),
                'team' => $d->team?->name,
                'position' => $d->position,
                'points' => $d->points,
                'is_champion' => $d->is_champion,
            ]);
    }

    /**
     * Шампионите по сезони (галерия), най-новите първо.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function champions(): Collection
    {
        return F2Driver::query()
            ->where('is_champion', true)
            ->with(['season', 'team'])
            ->get()
            ->sortByDesc(fn (F2Driver $d) => $d->season?->year)
            ->map(fn (F2Driver $d) => [
                'year' => $d->season?->year,
                'name' => $d->fullName(),
                'flag' => CountryFlag::iso2($d->country_code),
                'team' => $d->team?->name,
            ])
            ->values();
    }
}
