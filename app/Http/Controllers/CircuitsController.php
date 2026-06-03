<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Race;
use App\Services\Circuits\CircuitStatsService;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class CircuitsController extends Controller
{
    public function __construct(private readonly CircuitStatsService $stats) {}

    public function index(): Response
    {
        $circuits = Race::query()
            ->whereNotNull('jolpica_id')
            ->orderBy('circuit')
            ->get(['jolpica_id', 'circuit', 'country'])
            ->unique('jolpica_id')
            ->values()
            ->map(fn (Race $r) => [
                'slug' => $r->jolpica_id,
                'name' => $r->circuit,
                'country' => $r->country,
                'has_track' => File::exists(resource_path("svg/circuits/{$r->jolpica_id}.svg")),
            ]);

        return Inertia::render('Circuits/Index', ['circuits' => $circuits]);
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
            'standings' => $this->stats->getAllTimeDriverStandings($slug),
            'lastWinners' => $this->stats->getLastWinners($slug),
            'records' => $this->stats->getRecords($slug),
            'lastRace' => $this->stats->getLastRace($slug),
        ]);
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
            'albert_park' => ['length' => 5.278, 'turns' => 14, 'type' => 'Полу-улична'],
            'red_bull_ring' => ['length' => 4.318, 'turns' => 10, 'type' => 'Постоянна'],
            'hungaroring' => ['length' => 4.381, 'turns' => 14, 'type' => 'Постоянна'],
            'zandvoort' => ['length' => 4.259, 'turns' => 14, 'type' => 'Постоянна'],
            'americas' => ['length' => 5.513, 'turns' => 20, 'type' => 'Постоянна'],
            'rodriguez' => ['length' => 4.304, 'turns' => 17, 'type' => 'Постоянна'],
            'losail' => ['length' => 5.419, 'turns' => 16, 'type' => 'Постоянна'],
            'yas_marina' => ['length' => 5.281, 'turns' => 16, 'type' => 'Постоянна'],
            'villeneuve' => ['length' => 4.361, 'turns' => 14, 'type' => 'Полу-улична'],
            'shanghai' => ['length' => 5.451, 'turns' => 16, 'type' => 'Постоянна'],
            'madring' => ['length' => 5.474, 'turns' => 22, 'type' => 'Улична'],
            'bahrain' => ['length' => 5.412, 'turns' => 15, 'type' => 'Постоянна'],
        ][$slug] ?? [];
    }
}
