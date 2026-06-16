<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\ConstructorCanonical;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Rivalry;
use App\Models\TeamNewsItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Генерира public/sitemap.xml от публичните страници (статични + динамични
 * същности). Стартирай след голяма промяна в съдържанието.
 */
class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Генерира public/sitemap.xml с публичните URL-и.';

    public function handle(): int
    {
        $urls = $this->urls();

        file_put_contents(public_path('sitemap.xml'), $this->buildXml($urls));

        $this->info('Генериран sitemap с '.$urls->count().' URL-а → public/sitemap.xml');

        return self::SUCCESS;
    }

    /**
     * Всички публични URL-и (абсолютни).
     *
     * @return Collection<int, string>
     */
    public function urls(): Collection
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
        ];
        foreach ($featureStatic as $flag => $routes) {
            if (config("features.{$flag}")) {
                $names = $names->concat($routes);
            }
        }

        $urls = $names->map(fn ($name) => route($name));

        // V1 динамични същности.
        $urls = $urls
            ->concat(DriverCanonical::query()->pluck('slug')->map(fn ($s) => route('drivers.show', $s)))
            ->concat(ConstructorCanonical::query()->where('total_races', '>', 0)->pluck('slug')->map(fn ($s) => route('teams.show', $s)))
            ->concat(
                TeamNewsItem::query()
                    ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
                    ->pluck('slug')
                    ->map(fn ($s) => route('news.show', $s))
            );

        // V2 динамични същности — само при включен флаг.
        if (config('features.circuits')) {
            $urls = $urls->concat(Race::query()->whereNotNull('jolpica_id')->distinct()->pluck('jolpica_id')->map(fn ($s) => route('circuits.show', $s)));
        }
        if (config('features.rivalries')) {
            $urls = $urls->concat(Rivalry::query()->pluck('slug')->map(fn ($s) => route('rivalries.show', $s)));
        }

        return $urls->unique()->values();
    }

    /**
     * @param  Collection<int, string>  $urls
     */
    private function buildXml(Collection $urls): string
    {
        $entries = $urls
            ->map(fn (string $url) => '  <url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>')
            ->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$entries."\n"
            .'</urlset>'."\n";
    }
}
