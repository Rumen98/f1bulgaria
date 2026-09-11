<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\F2Race;
use App\Models\Race;
use App\Models\Season;
use App\Services\Circuits\CircuitStatsService;
use App\Support\CountryFlag;
use App\Support\DriverName;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class CircuitsController extends Controller
{
    public function __construct(private readonly CircuitStatsService $stats) {}

    public function index(Request $request): Response
    {
        $filter = (string) $request->query('filter', 'all'); // all|active|historical
        $currentYear = Season::current()?->year ?? (int) Season::query()->max('year');
        $activeThreshold = $currentYear - 2; // последни 3 сезона (текущ + 2)

        // Една директорийна проверка вместо File::exists() в цикъла.
        $tracks = collect(File::glob(resource_path('svg/circuits/*.svg')))
            ->mapWithKeys(fn ($p) => [pathinfo($p, PATHINFO_FILENAME) => true]);

        $circuits = Race::query()
            ->whereNotNull('races.jolpica_id')
            ->join('seasons', 'seasons.id', '=', 'races.season_id')
            ->groupBy('races.jolpica_id')
            ->selectRaw('races.jolpica_id as slug, MAX(races.circuit) as name, MAX(races.country) as country, MAX(seasons.year) as last_year')
            ->get()
            ->map(fn ($r) => [
                'slug' => $r->slug,
                'name' => $r->name,
                'country' => $r->country,
                'last_race_year' => (int) $r->last_year,
                'is_active' => (int) $r->last_year >= $activeThreshold,
                'has_track' => $tracks->has($r->slug),
            ])
            // Активни първо, после по година на последното състезание (низходящо).
            ->sortByDesc(fn ($c) => ($c['is_active'] ? 1 : 0) * 100000 + $c['last_race_year'])
            ->values();

        $counts = [
            'all' => $circuits->count(),
            'active' => $circuits->where('is_active', true)->count(),
            'historical' => $circuits->where('is_active', false)->count(),
        ];

        $filtered = match ($filter) {
            'active' => $circuits->where('is_active', true)->values(),
            'historical' => $circuits->where('is_active', false)->values(),
            default => $circuits,
        };

        return Inertia::render('Circuits/Index', [
            'circuits' => $filtered,
            'activeFilter' => $filter,
            'counts' => $counts,
        ]);
    }

    public function show(string $slug): Response
    {
        $reference = Race::query()
            ->where('jolpica_id', $slug)
            ->orderByDesc('race_datetime_utc')
            ->first();

        abort_if($reference === null, 404);

        $first = Race::query()->where('jolpica_id', $slug)->with('season')->orderBy('race_datetime_utc')->first();
        $next = Race::query()->where('jolpica_id', $slug)->where('race_datetime_utc', '>', now())->orderBy('race_datetime_utc')->first();
        $refRace = $next ?? $reference;

        $meta = $this->meta($slug);

        app(Seo::class)
            ->title($reference->circuit)
            ->description($reference->circuit.' — очертание на пистата, рекорди и статистика от Формула 1.')
            ->canonical(route('circuits.show', $slug));

        return Inertia::render('Circuits/Show', [
            'circuit' => [
                'slug' => $slug,
                'name' => $reference->circuit,
                'country' => $reference->country,
                'length_km' => $meta['length'] ?? null,
                'turns' => $meta['turns'] ?? null,
                'type' => $meta['type'] ?? null,
                'first_gp' => $first?->season?->year,
                'races_count' => Race::where('jolpica_id', $slug)->count(),
                'next_or_last_label' => $next ? 'Следващо състезание' : 'Последно състезание',
                'next_or_last_date' => $refRace->race_datetime_utc?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
            ],
            'technical' => config("circuit-data.{$slug}"),
            'standings' => $this->stats->getAllTimeDriverStandings($slug),
            'lastWinners' => $this->stats->getLastWinners($slug),
            'records' => $this->stats->getRecords($slug),
            'lastRace' => $this->stats->getLastRace($slug),
            'f2Winners' => $this->f2Winners($slug),
        ]);
    }

    /**
     * Победители във F2 главните състезания на тази писта (по сезони, най-новите
     * първо). Празно ако няма F2 данни за пистата.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function f2Winners(string $slug): Collection
    {
        return F2Race::query()
            ->where('circuit_jolpica_id', $slug)
            ->with(['season', 'sessions' => fn ($q) => $q->where('session_type', 'feature_race')
                ->with(['results' => fn ($r) => $r->where('position', 1)->with('driver')])])
            ->get()
            ->map(function (F2Race $race) {
                $winner = $race->sessions->first()?->results->first()?->driver;

                return $winner === null ? null : [
                    'year' => $race->season?->year,
                    'driver' => DriverName::display($winner->slug, $winner->fullName()),
                    'slug' => $winner->slug,
                    'flag' => CountryFlag::iso2($winner->country_code),
                    'race_slug' => $race->slug,
                ];
            })
            ->filter()
            ->sortByDesc('year')
            ->values();
    }

    /**
     * Базова мета за основните писти (дължина км, завои, тип). Останалите → "—".
     *
     * @return array{length?:float, turns?:int, type?:string}
     */
    private function meta(string $slug): array
    {
        return [
            'monaco' => ['length' => 3.337, 'turns' => 19, 'type' => 'Улична'],
            'monza' => ['length' => 5.793, 'turns' => 11, 'type' => 'Постоянна'],
            'spa' => ['length' => 7.004, 'turns' => 19, 'type' => 'Постоянна'],
            'silverstone' => ['length' => 5.891, 'turns' => 18, 'type' => 'Постоянна'],
            'imola' => ['length' => 4.909, 'turns' => 19, 'type' => 'Постоянна'],
            'suzuka' => ['length' => 5.807, 'turns' => 18, 'type' => 'Постоянна'],
            'catalunya' => ['length' => 4.657, 'turns' => 14, 'type' => 'Постоянна'],
            'jeddah' => ['length' => 6.174, 'turns' => 27, 'type' => 'Улична'],
            'miami' => ['length' => 5.412, 'turns' => 19, 'type' => 'Улична'],
            'vegas' => ['length' => 6.201, 'turns' => 17, 'type' => 'Улична'],
            'baku' => ['length' => 6.003, 'turns' => 20, 'type' => 'Улична'],
            'marina_bay' => ['length' => 4.940, 'turns' => 19, 'type' => 'Улична'],
            'interlagos' => ['length' => 4.309, 'turns' => 15, 'type' => 'Постоянна'],
            'albert_park' => ['length' => 5.278, 'turns' => 14, 'type' => 'Полуулична'],
            'red_bull_ring' => ['length' => 4.318, 'turns' => 10, 'type' => 'Постоянна'],
            'hungaroring' => ['length' => 4.381, 'turns' => 14, 'type' => 'Постоянна'],
            'zandvoort' => ['length' => 4.259, 'turns' => 14, 'type' => 'Постоянна'],
            'americas' => ['length' => 5.513, 'turns' => 20, 'type' => 'Постоянна'],
            'rodriguez' => ['length' => 4.304, 'turns' => 17, 'type' => 'Постоянна'],
            'losail' => ['length' => 5.419, 'turns' => 16, 'type' => 'Постоянна'],
            'yas_marina' => ['length' => 5.281, 'turns' => 16, 'type' => 'Постоянна'],
            'villeneuve' => ['length' => 4.361, 'turns' => 14, 'type' => 'Полуулична'],
            'shanghai' => ['length' => 5.451, 'turns' => 16, 'type' => 'Постоянна'],
            'madring' => ['length' => 5.474, 'turns' => 22, 'type' => 'Улична'],
            'bahrain' => ['length' => 5.412, 'turns' => 15, 'type' => 'Постоянна'],
        ][$slug] ?? [];
    }
}
