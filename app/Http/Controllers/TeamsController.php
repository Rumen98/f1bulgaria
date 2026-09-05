<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ResultSessionType;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\Races\RaceNameLocalizer;
use App\Services\Standings\StandingsService;
use App\Services\Teams\TeamStatsService;
use App\Support\BulgarianSort;
use App\Support\CountryFlag;
use App\Support\DriverName;
use App\Support\Seo;
use Illuminate\Http\Request;
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
        return Inertia::render('Teams/Index', [
            'season' => Season::current()?->year,
            'teams' => $this->stats->getTeamIndex(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        // Идентичността идва от каноничния модел (един запис на отбор, легендите включени).
        $canonical = $this->stats->getCanonicalBySlug($slug);
        abort_if($canonical === null, 404);

        // Кирилицата води — българинът търси „Ферари“, не „Ferrari“.
        $nameBg = config("team-brands.{$canonical->slug}.name_bg");
        $displayName = is_string($nameBg) && $nameBg !== '' ? $nameBg : $canonical->name;
        $both = $displayName === $canonical->name ? $canonical->name : "{$displayName} ({$canonical->name})";

        app(Seo::class)
            ->title($displayName)
            ->description("{$displayName} във Формула 1 — статистика, пилоти, победи, титли и история на конструктора. {$both}.")
            ->canonical(route('teams.show', $slug))
            ->schema([
                '@type' => 'SportsTeam',
                'name' => $displayName,
                'alternateName' => $canonical->name,
                'url' => route('teams.show', $slug),
                'sport' => 'Формула 1',
            ]);

        // Годините, в които отборът е участвал (за season dropdown), най-новите първо.
        $years = Constructor::query()
            ->where('constructors.canonical_id', $canonical->id)
            ->join('seasons', 'seasons.id', '=', 'constructors.season_id')
            ->orderByDesc('seasons.year')
            ->pluck('seasons.year')
            ->map(fn ($y) => (int) $y)
            ->unique()
            ->values();

        $requested = (int) $request->query('season');
        $selectedYear = $years->contains($requested) ? $requested : $years->first();

        // Per-season ред за избрания сезон — за състава, новините и класирането.
        $team = $selectedYear !== null
            ? Constructor::query()
                ->where('constructors.canonical_id', $canonical->id)
                ->join('seasons', 'seasons.id', '=', 'constructors.season_id')
                ->where('seasons.year', $selectedYear)
                ->select('constructors.*')
                ->with(['season', 'drivers'])
                ->first()
            : null;

        $season = $team?->season;

        // Класиране на пилотите за избрания сезон.
        $driverStandings = $season
            ? $this->standings->drivers($season)->keyBy(fn ($r) => $r['driver']->id)
            : collect();

        // Редът на състава се определя след map-ването, по кирилското име;
        // тук колекцията служи и за ID-тата на последните резултати.
        $roster = $team ? $team->drivers : collect();

        return Inertia::render('Teams/Show', [
            'team' => [
                'slug' => $canonical->slug,
                'name' => $canonical->name,
                'name_bg' => config("team-brands.{$canonical->slug}.name_bg"),
                'color_hex' => $canonical->color_hex ?? '#e10600',
                'description' => $this->description($canonical->slug),
                'is_active' => $canonical->is_active,
                'first_year' => $canonical->first_race_at?->year,
                'last_year' => $canonical->last_race_at?->year,
            ],
            'stats' => $this->stats->getStatsForCanonical($canonical),
            'drivers' => $roster->map(fn (Driver $d) => [
                'slug' => $d->slug,
                'name' => DriverName::display($d->slug, $d->fullName()),
                'number' => $d->permanent_number,
                'code' => $d->driver_code,
                'flag' => CountryFlag::iso2($d->country_code),
                'points' => $driverStandings->get($d->id)['points'] ?? 0,
                'position' => $driverStandings->get($d->id)['position'] ?? null,
            ])
                ->sortBy(fn (array $d) => BulgarianSort::key($d['name']))
                ->values(),
            'news' => $team ? $this->news($team) : collect(),
            'recentResults' => $this->recentResults($roster->pluck('id')),
            'season' => $selectedYear,
            'seasons' => $years,
            'selectedSeason' => $selectedYear,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function news(Constructor $team): Collection
    {
        return $team->newsItems()
            ->inMainFeed()
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn (TeamNewsItem $n) => [
                // slug-ът води към нашата статия; external_url е само резерва
                // за стари записи без slug.
                'slug' => $n->slug,
                'title' => $n->title_bg ?? $n->title_original,
                'summary' => $n->summary_bg,
                'classification' => $n->classification?->label(),
                'url' => $n->external_url,
                'published_at' => $n->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
            ]);
    }

    /**
     * Последните 5 състезания (през цялата история на отбора) с резултати на
     * някой от пилотите му.
     *
     * @param  Collection<int, int>  $driverIds
     * @return Collection<int, array<string, mixed>>
     */
    private function recentResults(Collection $driverIds): Collection
    {
        if ($driverIds->isEmpty()) {
            return collect();
        }

        return Race::query()
            ->whereHas('results', fn ($q) => $q->whereIn('driver_id', $driverIds))
            ->with(['results' => fn ($q) => $q->whereIn('driver_id', $driverIds)->with('driver')])
            ->orderByDesc('race_datetime_utc')
            ->limit(5)
            ->get()
            ->map(fn (Race $race) => [
                'race' => app(RaceNameLocalizer::class)->localize($race->jolpica_id, $race->name),
                // id и slug пътуват, за да станат редовете линкове — иначе
                // страницата на отбор е сляпа улица към състезания и пилоти.
                'race_id' => $race->id,
                'date' => $race->race_datetime_utc?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
                'points' => (float) $race->results->sum('points'),
                'finishes' => $race->results
                    ->where('session_type', ResultSessionType::Race)
                    ->sortBy('position')
                    ->map(fn ($r) => [
                        'driver' => $r->driver
                            ? DriverName::display($r->driver->slug, $r->driver->fullName())
                            : null,
                        'slug' => $r->driver?->slug,
                        'position' => $r->position,
                    ])->values(),
            ]);
    }

    private function description(string $slug): string
    {
        return [
            'ferrari' => 'Скудерия Ферари — най-старият и емблематичен отбор във Формула 1, базиран в Маранело.',
            'mclaren' => 'Макларън — британски гранд с богата история и едни от най-успешните сезони в спорта.',
            'red-bull' => 'Ред Бул Рейсинг — доминираща сила в съвременната ера на Формула 1.',
            'mercedes' => 'Мерцедес-АМГ — сребърните стрели, рекордьори по последователни титли.',
            'williams' => 'Уилямс — легендарен британски отбор с девет конструкторски титли.',
            'aston-martin' => 'Астън Мартин — амбициозен проект с поглед към върха.',
            'alpine-f1-team' => 'Алпин — френският отбор на Рено груп.',
            'haas-f1-team' => 'Хаас — американският отбор, дебютирал през 2016 г.',
            'rb-f1-team' => 'РБ — сестринският отбор на Ред Бул, школата за бъдещите таланти.',
            'audi' => 'Ауди — германският гигант, който поема отбора от Заубер за новата ера.',
            'cadillac-f1-team' => 'Кадилак — американската марка, която се присъединява към стартовата решетка.',
        ][$slug] ?? 'Отбор във Формула 1. Подробно описание предстои.';
    }
}
