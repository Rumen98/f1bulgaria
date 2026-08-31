<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\NewsStatus;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\TeamNewsItem;
use App\Services\Races\RaceNameLocalizer;
use App\Support\DriverName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Търсене из целия сайт: новини, пилоти, отбори, състезания, писти и речника.
 *
 * Съзнателно е LIKE, а не FULLTEXT индекс. При ~1700 статии разликата е под
 * милисекунда, а MySQL FULLTEXT няма българско стемване — печалбата би била
 * само сложност. Ако архивът мине десетки хиляди редове, тогава е моментът.
 *
 * Кирилските имена на пилоти, отбори и състезания НЕ са в базата, а в
 * config/*.php. Затова всяка от тези групи се търси двупосочно: LIKE по
 * латинското име в SQL + съвпадение по конфигурационната карта в паметта
 * (тя е няколкостотин реда — филтрирането ѝ е безплатно).
 */
class SiteSearchService
{
    /** Минимална дължина на заявката — под 2 знака резултатите са безсмислени. */
    public const MIN_LENGTH = 2;

    /** Таван на резултатите в една група. */
    private const PER_GROUP = 8;

    public function __construct(private readonly RaceNameLocalizer $raceNames) {}

    /**
     * @return array{groups: array<int, array{key:string, label:string, items:array<int, array<string, mixed>>}>, total: int}
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['groups' => [], 'total' => 0];
        }

        $groups = collect([
            ['key' => 'drivers', 'label' => 'Пилоти', 'items' => $this->drivers($term)],
            ['key' => 'teams', 'label' => 'Отбори', 'items' => $this->teams($term)],
            ['key' => 'races', 'label' => 'Състезания', 'items' => $this->races($term)],
            ['key' => 'circuits', 'label' => 'Писти', 'items' => $this->circuits($term)],
            ['key' => 'news', 'label' => 'Новини', 'items' => $this->news($term)],
            ['key' => 'glossary', 'label' => 'Речник', 'items' => $this->glossary($term)],
        ])
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'total' => collect($groups)->sum(fn (array $group) => count($group['items'])),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function drivers(string $term): array
    {
        // Slug-ове, чието БЪЛГАРСКО име съвпада — картата е в конфига, не в базата.
        $bgSlugs = $this->matchingConfigKeys('driver-names-bg', $term);

        $drivers = Driver::query()
            ->where(function (Builder $query) use ($term, $bgSlugs): void {
                $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('driver_code', 'like', "%{$term}%");

                if ($bgSlugs !== []) {
                    $query->orWhereIn('slug', $bgSlugs);
                }
            })
            ->with('constructor')
            ->orderByDesc('season_id')
            ->get();

        // Един пилот има ред за всеки сезон — оставяме само най-скорошния.
        return $drivers
            ->unique('slug')
            ->take(self::PER_GROUP)
            ->map(fn (Driver $driver) => [
                'title' => DriverName::display($driver->slug, $driver->fullName()),
                'subtitle' => $driver->fullName(),
                'meta' => $driver->constructor?->name,
                'url' => route('drivers.show', $driver->slug),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teams(string $term): array
    {
        $bgSlugs = $this->matchingConfigKeys('team-brands', $term, 'name_bg');

        return Constructor::query()
            ->where(function (Builder $query) use ($term, $bgSlugs): void {
                $query->where('name', 'like', "%{$term}%");

                if ($bgSlugs !== []) {
                    $query->orWhereIn('slug', $bgSlugs);
                }
            })
            ->orderByDesc('season_id')
            ->get()
            ->unique('slug')
            ->take(self::PER_GROUP)
            ->map(fn (Constructor $team) => [
                'title' => config("team-brands.{$team->slug}.name_bg", $team->name),
                'subtitle' => $team->name,
                'meta' => null,
                'url' => route('teams.show', $team->slug),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function races(string $term): array
    {
        $bgIds = $this->matchingConfigKeys('race-names-bg', $term);

        return Race::query()
            ->where(function (Builder $query) use ($term, $bgIds): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('circuit', 'like', "%{$term}%")
                    ->orWhere('country', 'like', "%{$term}%");

                if ($bgIds !== []) {
                    $query->orWhereIn('jolpica_id', $bgIds);
                }
            })
            ->with('season')
            ->orderByDesc('race_datetime_utc')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Race $race) => [
                'title' => $this->raceNames->localize($race->jolpica_id, $race->name),
                'subtitle' => $race->circuit,
                'meta' => $race->season?->year !== null ? (string) $race->season->year : null,
                'url' => route('races.show', $race->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Пистите се извеждат от състезанията (няма собствена таблица), затова се
     * групират по jolpica_id. Скрити са, когато модулът е изключен.
     *
     * @return array<int, array<string, mixed>>
     */
    private function circuits(string $term): array
    {
        if (! config('features.circuits')) {
            return [];
        }

        return Race::query()
            ->whereNotNull('jolpica_id')
            ->where(function (Builder $query) use ($term): void {
                $query->where('circuit', 'like', "%{$term}%")
                    ->orWhere('country', 'like', "%{$term}%");
            })
            ->groupBy('jolpica_id')
            ->selectRaw('jolpica_id, MAX(circuit) as circuit_name, MAX(country) as country_name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn ($row) => [
                'title' => $row->circuit_name,
                'subtitle' => $row->country_name,
                'meta' => null,
                'url' => route('circuits.show', $row->jolpica_id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function news(string $term): array
    {
        return TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->whereNotNull('title_bg')
            ->where(function (Builder $query) use ($term): void {
                $query->where('title_bg', 'like', "%{$term}%")
                    ->orWhere('summary_bg', 'like', "%{$term}%")
                    ->orWhere('title_original', 'like', "%{$term}%");
            })
            ->orderByDesc('published_at')
            ->limit(self::PER_GROUP)
            ->get(['slug', 'title_bg', 'summary_bg', 'published_at'])
            ->map(fn (TeamNewsItem $item) => [
                'title' => $item->title_bg,
                'subtitle' => $item->summary_bg,
                'meta' => $item->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y'),
                'url' => route('news.show', $item->slug),
            ])
            ->values()
            ->all();
    }

    /**
     * Речникът живее в config/f1-glossary.php — филтрира се в паметта.
     *
     * @return array<int, array<string, mixed>>
     */
    private function glossary(string $term): array
    {
        $needle = mb_strtolower($term);

        /** @var array<int, array{term_bg:string, term_en:string, definition_bg:string, category:string}> $terms */
        $terms = config('f1-glossary.terms', []);

        return collect($terms)
            ->filter(fn (array $row): bool => str_contains(mb_strtolower($row['term_bg']), $needle)
                || str_contains(mb_strtolower($row['term_en']), $needle)
                || str_contains(mb_strtolower($row['definition_bg']), $needle))
            ->take(self::PER_GROUP)
            ->map(fn (array $row) => [
                'title' => $row['term_bg'],
                'subtitle' => $row['definition_bg'],
                'meta' => $row['term_en'] !== $row['term_bg'] ? $row['term_en'] : null,
                'url' => route('terminology'),
            ])
            ->values()
            ->all();
    }

    /**
     * Ключовете на конфигурационна карта, чиято стойност съдържа търсенето.
     * Поддържа и плоска карта (slug => 'Име'), и вложена (slug => ['name_bg' => …]).
     *
     * @return array<int, string>
     */
    private function matchingConfigKeys(string $configKey, string $term, ?string $field = null): array
    {
        $needle = mb_strtolower($term);

        /** @var Collection<string, mixed> $map */
        $map = collect(config($configKey, []));

        return $map
            ->filter(function ($value) use ($needle, $field): bool {
                $name = $field !== null
                    ? (is_array($value) ? ($value[$field] ?? null) : null)
                    : $value;

                return is_string($name) && str_contains(mb_strtolower($name), $needle);
            })
            ->keys()
            ->all();
    }
}
