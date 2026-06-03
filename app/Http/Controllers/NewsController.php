<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    /** Категориите, които третираме като „анализи". */
    private const ANALYSIS = ['technical', 'rumor', 'business'];

    public function index(Request $request): Response
    {
        $cat = (string) $request->query('cat', 'all');

        $items = $this->visible()
            ->when($cat !== 'all', fn (Builder $q) => $this->applyCategory($q, $cat))
            ->orderByDesc('published_at')
            ->limit(40)
            ->get();

        // Featured = най-важната измежду най-новите (без активен филтър).
        $featured = $cat === 'all'
            ? $this->visible()->orderByDesc('published_at')->limit(12)->get()
                ->sortByDesc('importance_score')->first()
            : null;

        $list = $featured
            ? $items->reject(fn (TeamNewsItem $i) => $i->id === $featured->id)->values()
            : $items;

        return Inertia::render('News/Index', [
            'featured' => $featured ? $this->card($featured) : null,
            'items' => $list->map(fn (TeamNewsItem $i) => $this->card($i)),
            'categories' => $this->categories(),
            'activeCat' => $cat,
        ]);
    }

    /**
     * Собствена article страница — оригинален бг превод/резюме + ясно посочен източник.
     */
    public function show(string $slug): Response
    {
        $item = $this->visible()->where('slug', $slug)->first();

        abort_if($item === null, 404);

        return Inertia::render('News/Show', [
            'article' => [
                ...$this->card($item),
                'source' => $item->source?->name,
                'external_url' => $item->external_url,
                'canonical' => route('news.show', $item->slug),
            ],
            'related' => $this->related($item),
        ]);
    }

    /**
     * Свързани новини — от същия отбор или категория, най-нови първо.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function related(TeamNewsItem $item)
    {
        $query = $this->visible()->where('id', '!=', $item->id);

        if ($item->constructor_id !== null || $item->classification !== null) {
            $query->where(function (Builder $q) use ($item) {
                if ($item->constructor_id !== null) {
                    $q->where('constructor_id', $item->constructor_id);
                }
                if ($item->classification !== null) {
                    $q->orWhere('classification', $item->classification->value);
                }
            });
        }

        return $query->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (TeamNewsItem $i) => $this->card($i));
    }

    /**
     * @return Builder<TeamNewsItem>
     */
    private function visible(): Builder
    {
        return TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->with('constructor');
    }

    /**
     * @param  Builder<TeamNewsItem>  $query
     * @return Builder<TeamNewsItem>
     */
    private function applyCategory(Builder $query, string $cat): Builder
    {
        if ($cat === 'analysis') {
            return $query->whereIn('classification', self::ANALYSIS);
        }

        if (NewsClassification::tryFrom($cat) !== null) {
            return $query->where('classification', $cat);
        }

        return $query;
    }

    /**
     * @return array<int, array{key:string, label:string, count:int}>
     */
    private function categories(): array
    {
        $counts = (clone $this->visible())
            ->selectRaw('classification, count(*) as c')
            ->groupBy('classification')
            ->pluck('c', 'classification');

        $total = (int) $counts->sum();
        $analysis = collect(self::ANALYSIS)->sum(fn ($c) => (int) ($counts[$c] ?? 0));

        $cats = [
            ['key' => 'all', 'label' => 'Всички', 'count' => $total],
            ['key' => 'race', 'label' => 'Състезания', 'count' => (int) ($counts['race'] ?? 0)],
            ['key' => 'driver', 'label' => 'Пилоти', 'count' => (int) ($counts['driver'] ?? 0)],
            ['key' => 'analysis', 'label' => 'Анализи', 'count' => $analysis],
            ['key' => 'business', 'label' => 'Бизнес', 'count' => (int) ($counts['business'] ?? 0)],
        ];

        return array_values(array_filter($cats, fn ($c) => $c['key'] === 'all' || $c['count'] > 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(TeamNewsItem $item): array
    {
        return [
            'slug' => $item->slug,
            'title' => $item->title_bg ?? $item->title_original,
            'summary' => $item->summary_bg,
            'classification' => $item->classification?->label(),
            'importance' => $item->importance_score,
            'team' => $item->constructor?->name,
            'color' => $item->constructor?->color_hex,
            'published_at' => $item->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
            'url' => $item->external_url,
        ];
    }
}
