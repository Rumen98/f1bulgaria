<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * „Данни“ — рекапът от OpenF1 след всяко състезание, с графиките.
 *
 * Разделът е собствен, а не таб в новините: материалът е визуален и се
 * споделя сам по себе си, а новинарската емисия го погребва под заглавия.
 * Статията в /news остава, но е анонс с линк насам.
 */
class RaceDataController extends Controller
{
    public function index(): Response
    {
        $seasons = $this->seasonsWithRecaps();
        // Непозната или липсваща година пада към най-новата, вместо да върне
        // празна страница — така не се раждат безкрайни варианти за краулера.
        $requested = (int) request()->query('sezon');
        $season = in_array($requested, $seasons, true) ? $requested : ($seasons[0] ?? null);

        $recaps = RaceDataRecap::query()
            ->ready()
            ->with(['race:id,season_id,round,name,jolpica_id,circuit,country,race_datetime_utc'])
            ->join('races', 'races.id', '=', 'race_data_recaps.race_id')
            ->join('seasons', 'seasons.id', '=', 'races.season_id')
            ->when($season !== null, fn ($q) => $q->where('seasons.year', $season))
            ->orderByDesc('races.race_datetime_utc')
            ->select('race_data_recaps.*')
            ->get();

        app(Seo::class)
            ->title($season === null ? 'Данните от състезанията' : "Данните от състезанията {$season}")
            ->description('Какво казват данните след всяко състезание от Формула 1 — стратегии по гуми, темпо по обиколки, спечелени позиции и шампионатът. На български.')
            // Каноничният адрес е винаги без параметър: сезоните са изгледи на
            // един и същ раздел, не отделни страници.
            ->canonical(route('racedata.index'));

        return Inertia::render('RaceData/Index', [
            'races' => $this->cards($recaps),
            'seasons' => $seasons,
            'season' => $season,
        ]);
    }

    /**
     * Годините, за които изобщо има готов рекап — най-новата първа.
     *
     * @return array<int, int>
     */
    private function seasonsWithRecaps(): array
    {
        return RaceDataRecap::query()
            ->ready()
            ->join('races', 'races.id', '=', 'race_data_recaps.race_id')
            ->join('seasons', 'seasons.id', '=', 'races.season_id')
            ->distinct()
            ->orderByDesc('seasons.year')
            ->pluck('seasons.year')
            ->map(fn ($year) => (int) $year)
            ->all();
    }

    public function show(Race $race): Response
    {
        $recap = $race->dataRecap()->ready()->first();

        if ($recap === null) {
            // Състезание без готов рекап не е грешка в кода — просто още няма
            // какво да се покаже. 404 е честният отговор пред краулера.
            throw new NotFoundHttpException;
        }

        $race->loadMissing('season:id,year');
        $name = $race->name_bg;

        app(Seo::class)
            ->title("Данните от {$name}")
            ->description($this->metaDescription($recap, $name))
            ->canonical(route('racedata.show', $race->id));

        return Inertia::render('RaceData/Show', [
            'race' => [
                'id' => $race->id,
                'name' => $name,
                'round' => $race->round,
                'circuit' => $race->circuit,
                'country' => $race->country,
                'year' => $race->season?->year,
                'date' => $race->race_datetime_utc?->setTimezone('Europe/Sofia')->format('d.m.Y'),
            ],
            'headline' => $recap->headline,
            'body' => $this->paragraphs($recap),
            'facts' => $recap->facts,
            'charts' => $recap->charts,
            'newsSlug' => $recap->newsItem?->slug,
            'neighbours' => $this->neighbours($race),
        ]);
    }

    /**
     * @param  Collection<int, RaceDataRecap>  $recaps
     * @return array<int, array<string, mixed>>
     */
    private function cards(Collection $recaps): array
    {
        return $recaps
            ->filter(fn (RaceDataRecap $r) => $r->race !== null)
            ->map(fn (RaceDataRecap $r) => [
                'id' => $r->race->id,
                'name' => $r->race->name_bg,
                'round' => $r->race->round,
                'circuit' => $r->race->circuit,
                'country' => $r->race->country,
                'date' => $r->race->race_datetime_utc?->setTimezone('Europe/Sofia')->format('d.m.Y'),
                'winner' => $r->facts['winner'] ?? null,
                // Първите две точки стигат за карта — останалите са на страницата.
                'bullets' => array_slice((array) ($r->facts['bullets'] ?? []), 0, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function paragraphs(RaceDataRecap $recap): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n\n", (string) $recap->body_bg)),
            fn (string $p) => $p !== '',
        ));
    }

    private function metaDescription(RaceDataRecap $recap, string $name): string
    {
        $bullets = (array) ($recap->facts['bullets'] ?? []);

        return $bullets === []
            ? "Данните от {$name} — стратегии, темпо и позиции."
            : mb_substr(implode(' ', array_slice($bullets, 0, 2)), 0, 300);
    }

    /**
     * Предишният и следващият кръг, за които ИМА рекап — иначе стрелката води
     * към 404.
     *
     * @return array{prev: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    private function neighbours(Race $race): array
    {
        $at = $race->race_datetime_utc;

        if ($at === null) {
            return ['prev' => null, 'next' => null];
        }

        $query = fn (string $direction) => Race::query()
            ->whereHas('dataRecap', fn ($r) => $r->whereNotNull('generated_at'))
            ->whereNotNull('race_datetime_utc')
            ->when(
                $direction === 'prev',
                fn ($q) => $q->where('race_datetime_utc', '<', $at)->orderByDesc('race_datetime_utc'),
                fn ($q) => $q->where('race_datetime_utc', '>', $at)->orderBy('race_datetime_utc'),
            )
            ->first(['id', 'round', 'name', 'jolpica_id', 'race_datetime_utc']);

        $shape = fn (?Race $r) => $r === null ? null : [
            'id' => $r->id,
            'round' => $r->round,
            'name' => $r->name_bg,
        ];

        return [
            'prev' => $shape($query('prev')),
            'next' => $shape($query('next')),
        ];
    }
}
