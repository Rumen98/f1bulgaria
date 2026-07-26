<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\Comment;
use App\Models\TeamNewsItem;
use App\Support\Seo;
use App\Support\SeoSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    /** Категориите, които третираме като „анализи". */
    private const ANALYSIS = ['technical', 'rumor', 'business'];

    /** Новини на страница в безкрайния скрол. */
    private const PER_PAGE = 24;

    public function index(Request $request): Response
    {
        $cat = (string) $request->query('cat', 'all');

        // Featured = най-важната измежду най-новите (без активен филтър).
        $featured = $cat === 'all'
            ? $this->visible()->orderByDesc('published_at')->limit(12)->get()
                ->sortByDesc('importance_score')->first()
            : null;

        $items = $this->visible()
            ->when($cat !== 'all', fn (Builder $q) => $this->applyCategory($q, $cat))
            // Featured-ът е горе в собствен блок — изключваме го от решетката,
            // иначе излиза два пъти на първата страница.
            ->when($featured !== null, fn (Builder $q) => $q->whereKeyNot($featured->id))
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (TeamNewsItem $i) => $this->card($i));

        app(Seo::class)
            ->title($cat === 'all' ? 'Новини от Формула 1' : 'Новини: '.$this->catLabel($cat))
            ->description('Последните новини от Формула 1 на български — състезания, пилоти, техника и трансфери. Обновява се непрекъснато.')
            ->canonical($cat === 'all' ? route('news.index') : route('news.index', ['cat' => $cat]));

        return Inertia::render('News/Index', [
            'featured' => $featured ? $this->card($featured) : null,
            'items' => Inertia::scroll($items),
            'categories' => $this->categories(),
            'activeCat' => $cat,
        ]);
    }

    /**
     * Собствена article страница — оригинален бг превод/резюме + ясно посочен източник.
     */
    public function show(Request $request, string $slug): Response
    {
        $item = $this->visible()->where('slug', $slug)->first();

        abort_if($item === null, 404);

        $this->applyArticleSeo($item);

        return Inertia::render('News/Show', [
            'article' => [
                ...$this->card($item),
                'full_article' => $item->full_article_bg,
                'analysis' => $item->our_analysis_bg,
                'key_facts' => $item->key_facts ?? [],
                'source' => $item->source?->name,
                'external_url' => $item->external_url,
                'canonical' => route('news.show', $item->slug),
            ],
            'related' => $this->related($item),
            'comments' => $this->comments($item, $request),
        ]);
    }

    /**
     * Сървърни SEO метаданни за страницата на статия. Критично за социалните
     * мрежи: Facebook/Viber/Telegram четат само първоначалния HTML.
     */
    private function applyArticleSeo(TeamNewsItem $item): void
    {
        $title = $item->title_bg ?? $item->title_original;
        $description = $item->summary_bg ?? $item->content_snippet;
        $url = route('news.show', $item->slug);
        $image = $this->shareImage($item);
        $published = $item->published_at?->toIso8601String();
        $modified = $item->updated_at?->toIso8601String();

        app(Seo::class)
            ->title($title)
            ->description($description)
            ->image($image)
            ->type('article')
            ->canonical($url)
            ->dates($published, $modified)
            ->schema(SeoSchema::newsArticle(
                headline: $title,
                description: (string) $description,
                url: $url,
                image: $image,
                publishedAt: $published,
                modifiedAt: $modified,
                keyFacts: $item->key_facts ?? [],
            ))
            ->schema(SeoSchema::breadcrumbs([
                ['name' => 'Начало', 'url' => route('home')],
                ['name' => 'Новини', 'url' => route('news.index')],
                ['name' => $title, 'url' => $url],
            ]));
    }

    /**
     * Картинка за социалните карти. Снимка на пилот (когато новината е за
     * конкретен пилот) дава реална диференциация; иначе брандовият банер.
     */
    private function shareImage(TeamNewsItem $item): string
    {
        $image = $item->featured_image;

        if (is_array($image) && ($image['type'] ?? null) === 'driver_photo') {
            $photo = $image['data']['photo_url'] ?? null;

            if (is_string($photo) && str_starts_with($photo, 'http')) {
                return $photo;
            }
        }

        return asset('og-image.jpg');
    }

    private function catLabel(string $cat): string
    {
        return match ($cat) {
            'analysis' => 'Анализи',
            'race' => 'Състезания',
            'driver' => 'Пилоти',
            'business' => 'Бизнес',
            default => NewsClassification::tryFrom($cat)?->label() ?? 'Всички',
        };
    }

    /**
     * Коментарите на статията, хронологично (най-старите първо).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function comments(TeamNewsItem $item, Request $request): Collection
    {
        return $item->comments()
            ->with('user:id,name')
            ->oldest()
            ->get()
            ->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user?->name ?? 'Изтрит профил',
                'created_at_human' => $comment->created_at->diffForHumans(),
                'can_delete' => $request->user()?->can('delete', $comment) ?? false,
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
            'image' => $item->featured_image,
            'published_at' => $item->published_at?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
            // Машинно четима дата за <time datetime> — свежестта е ранкинг сигнал.
            'published_at_iso' => $item->published_at?->toIso8601String(),
            'url' => $item->external_url,
        ];
    }
}
