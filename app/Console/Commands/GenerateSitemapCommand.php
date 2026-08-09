<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\ConstructorCanonical;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Rivalry;
use App\Models\TeamNewsItem;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Генерира sitemap index + под-sitemap-и в public/.
 *
 * Разделянето е нарочно: при нов домейн crawl бюджетът е малък, а новините са
 * единственото свежо съдържание. В общ файл с хиляди статични страници те се
 * давят. Отделният news sitemap с lastmod дава ясен сигнал кое е ново.
 */
class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Генерира sitemap index + news/content sitemap-и в public/.';

    public function handle(): int
    {
        $news = $this->newsUrls();
        $content = $this->contentUrls();

        file_put_contents(public_path('sitemap-news.xml'), $this->buildUrlSet($news));
        file_put_contents(public_path('sitemap-content.xml'), $this->buildUrlSet($content));
        file_put_contents(public_path('sitemap.xml'), $this->buildIndex());

        $this->info(sprintf(
            'Sitemap: %d новини + %d страници (sitemap-news.xml, sitemap-content.xml, sitemap.xml).',
            $news->count(),
            $content->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Всички публични URL-и (за тестове и обратна съвместимост).
     *
     * @return Collection<int, string>
     */
    public function urls(): Collection
    {
        return $this->contentUrls()
            ->concat($this->newsUrls())
            ->pluck('loc')
            ->unique()
            ->values();
    }

    /**
     * Новинарските статии — най-новите първо, с дата на промяна.
     *
     * @return Collection<int, array{loc:string, lastmod:?CarbonInterface}>
     */
    private function newsUrls(): Collection
    {
        return TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->orderByDesc('published_at')
            ->get(['slug', 'published_at', 'updated_at'])
            ->map(fn (TeamNewsItem $item) => [
                'loc' => route('news.show', $item->slug),
                'lastmod' => $item->updated_at ?? $item->published_at,
            ]);
    }

    /**
     * Статичните страници и справочните същности (пилоти, отбори, писти).
     *
     * @return Collection<int, array{loc:string, lastmod:?CarbonInterface}>
     */
    private function contentUrls(): Collection
    {
        // V1 статични страници — винаги в sitemap.
        $names = collect([
            'home', 'calendar', 'standings', 'leaderboard', 'teams.index',
            'drivers.index', 'news.index', 'terminology', 'privacy', 'terms', 'contact',
        ]);

        // V2 страници — само ако флагът е включен (иначе рутът връща 404).
        $featureStatic = [
            'circuits' => ['circuits.index'],
            'compare' => ['compare.index'],
            'rivalries' => ['rivalries.index'],
            'tsolov' => ['tsolov'],
            'history' => ['history', 'history.world', 'history.bulgaria'],
            'f2' => ['f2'],
            'live_timing' => ['live'],
            'quiz' => ['quiz'],
        ];
        foreach ($featureStatic as $flag => $routes) {
            if (config("features.{$flag}")) {
                $names = $names->concat($routes);
            }
        }

        /** @var Collection<int, array{loc:string, lastmod:?CarbonInterface}> $urls */
        $urls = $names->map(fn ($name) => ['loc' => route($name), 'lastmod' => null]);

        // V1 динамични същности.
        $urls = $urls
            ->concat(DriverCanonical::query()->get(['slug', 'updated_at'])
                ->map(fn (DriverCanonical $d) => ['loc' => route('drivers.show', $d->slug), 'lastmod' => $d->updated_at]))
            ->concat(ConstructorCanonical::query()->where('total_races', '>', 0)->get(['slug', 'updated_at'])
                ->map(fn (ConstructorCanonical $c) => ['loc' => route('teams.show', $c->slug), 'lastmod' => $c->updated_at]));

        // Състезанията са линкнати от началната страница, но липсваха в sitemap-а.
        $urls = $urls->concat(
            Race::query()->orderByDesc('race_datetime_utc')->get(['id', 'updated_at'])
                ->map(fn (Race $r) => ['loc' => route('races.show', $r->id), 'lastmod' => $r->updated_at])
        );

        // V2 динамични същности — само при включен флаг.
        if (config('features.circuits')) {
            $urls = $urls->concat(
                Race::query()->whereNotNull('jolpica_id')->distinct()->pluck('jolpica_id')
                    ->map(fn ($s) => ['loc' => route('circuits.show', $s), 'lastmod' => null])
            );
        }
        if (config('features.rivalries')) {
            $urls = $urls->concat(
                Rivalry::query()->pluck('slug')
                    ->map(fn ($s) => ['loc' => route('rivalries.show', $s), 'lastmod' => null])
            );
        }

        return $urls->unique('loc')->values();
    }

    /**
     * @param  Collection<int, array{loc:string, lastmod:?CarbonInterface}>  $urls
     */
    private function buildUrlSet(Collection $urls): string
    {
        $entries = $urls->map(function (array $url): string {
            $loc = htmlspecialchars($url['loc'], ENT_XML1);
            $lastmod = $url['lastmod'] instanceof CarbonInterface
                ? '<lastmod>'.$url['lastmod']->toAtomString().'</lastmod>'
                : '';

            return "  <url><loc>{$loc}</loc>{$lastmod}</url>";
        })->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$entries."\n"
            .'</urlset>'."\n";
    }

    private function buildIndex(): string
    {
        $now = now()->toAtomString();

        $entries = collect(['sitemap-news.xml', 'sitemap-content.xml'])
            ->map(fn (string $file) => '  <sitemap><loc>'
                .htmlspecialchars(rtrim((string) config('app.url'), '/').'/'.$file, ENT_XML1)
                ."</loc><lastmod>{$now}</lastmod></sitemap>")
            ->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$entries."\n"
            .'</sitemapindex>'."\n";
    }
}
