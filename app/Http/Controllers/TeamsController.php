<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsStatus;
use App\Enums\ResultSessionType;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\Standings\StandingsService;
use App\Services\Teams\TeamStatsService;
use App\Support\CountryFlag;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    public function __construct(
        private readonly TeamStatsService $stats,
        private readonly StandingsService $standings,
    ) {}

    public function index(): Response
    {
        $season = Season::current();

        if ($season === null) {
            return Inertia::render('Teams/Index', ['season' => null, 'teams' => []]);
        }

        $standings = $this->standings->constructors($season)
            ->keyBy(fn ($row) => $row['constructor']->id);

        // Всички отбори за сезона (дори без резултати), подредени по класиране.
        $teams = $season->constructors()->orderBy('name')->get()
            ->map(fn (Constructor $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
                'color_hex' => $c->color_hex,
                'position' => $standings->get($c->id)['position'] ?? null,
                'points' => $standings->get($c->id)['points'] ?? 0.0,
            ])
            ->sortBy(fn ($t) => $t['position'] ?? 999)
            ->values();

        return Inertia::render('Teams/Index', [
            'season' => $season->year,
            'teams' => $teams,
        ]);
    }

    public function show(string $slug): Response
    {
        $season = Season::current();
        abort_if($season === null, 404);

        $team = Constructor::query()
            ->where('season_id', $season->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $drivers = $team->drivers()->orderBy('last_name')->get();
        $driverStandings = $this->standings->drivers($season)->keyBy(fn ($r) => $r['driver']->id);

        return Inertia::render('Teams/Show', [
            'team' => [
                'slug' => $team->slug,
                'name' => $team->name,
                'color_hex' => $team->color_hex ?? '#e10600',
                'description' => $this->description($team->slug),
            ],
            'stats' => $this->stats->getSeasonStats($team, $season)->toArray(),
            'drivers' => $drivers->map(fn (Driver $d) => [
                'slug' => $d->slug,
                'name' => $d->fullName(),
                'number' => $d->permanent_number,
                'code' => $d->driver_code,
                'flag' => CountryFlag::emoji($d->country_code),
                'points' => $driverStandings->get($d->id)['points'] ?? 0,
                'position' => $driverStandings->get($d->id)['position'] ?? null,
            ]),
            'news' => $this->news($team),
            'recentResults' => $this->recentResults($team, $season, $drivers->pluck('id')),
            'season' => $season->year,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function news(Constructor $team): Collection
    {
        $visible = collect(NewsStatus::publiclyVisible())->map->value->all();

        return $team->newsItems()
            ->whereIn('status', $visible)
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn (TeamNewsItem $n) => [
                'title' => $n->title_bg ?? $n->title_original,
                'summary' => $n->summary_bg,
                'classification' => $n->classification?->label(),
                'url' => $n->external_url,
                'published_at' => $n->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
            ]);
    }

    /**
     * @param  Collection<int, int>  $driverIds
     * @return Collection<int, array<string, mixed>>
     */
    private function recentResults(Constructor $team, Season $season, Collection $driverIds): Collection
    {
        return Race::query()
            ->where('season_id', $season->id)
            ->whereHas('results', fn ($q) => $q->whereIn('driver_id', $driverIds))
            ->with(['results' => fn ($q) => $q->whereIn('driver_id', $driverIds)->with('driver')])
            ->orderByDesc('race_datetime_utc')
            ->limit(5)
            ->get()
            ->map(fn (Race $race) => [
                'race' => $race->name,
                'date' => $race->race_datetime_utc?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
                'points' => (float) $race->results->sum('points'),
                'finishes' => $race->results
                    ->where('session_type', ResultSessionType::Race)
                    ->sortBy('position')
                    ->map(fn ($r) => [
                        'driver' => $r->driver?->fullName(),
                        'position' => $r->position,
                    ])->values(),
            ]);
    }

    private function description(string $slug): string
    {
        return [
            'ferrari' => 'Скудерия Ферари — най-старият и емблематичен отбор във Формула 1, базиран в Маранело.',
            'mclaren' => 'Макларън — британски гранд с богата история и едни от най-успешните сезони в спорта.',
            'red_bull' => 'Ред Бул Рейсинг — доминираща сила в съвременната ера на Формула 1.',
            'mercedes' => 'Мерцедес-АМГ — сребърните стрели, рекордьори по последователни титли.',
            'williams' => 'Уилямс — легендарен британски отбор с девет конструкторски титли.',
            'aston_martin' => 'Астън Мартин — амбициозен проект с поглед към върха.',
            'alpine' => 'Алпин — френският отбор на Рено груп.',
        ][$slug] ?? 'Отбор от текущия сезон във Формула 1. Подробно описание предстои.';
    }
}
