<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConstructorCanonical;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Rivalry;
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
        $static = collect([
            'home', 'calendar', 'standings', 'leaderboard', 'teams.index', 'drivers.index',
            'circuits.index', 'compare.index', 'rivalries.index', 'history', 'history.world',
            'history.bulgaria', 'tsolov', 'terminology', 'news.index', 'f2', 'live',
        ])->map(fn ($name) => route($name));

        $drivers = DriverCanonical::query()->pluck('slug')->map(fn ($s) => route('drivers.show', $s));
        $teams = ConstructorCanonical::query()->where('total_races', '>', 0)->pluck('slug')->map(fn ($s) => route('teams.show', $s));
        $circuits = Race::query()->whereNotNull('jolpica_id')->distinct()->pluck('jolpica_id')->map(fn ($s) => route('circuits.show', $s));
        $rivalries = Rivalry::query()->pluck('slug')->map(fn ($s) => route('rivalries.show', $s));

        return $static
            ->concat($drivers)
            ->concat($teams)
            ->concat($circuits)
            ->concat($rivalries)
            ->unique()
            ->values();
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
