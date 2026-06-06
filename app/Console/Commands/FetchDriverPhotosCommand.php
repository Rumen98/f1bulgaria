<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DriverCanonical;
use App\Models\Season;
use App\Services\Drivers\DriverPhotoFetcher;
use Illuminate\Console\Command;

/**
 * Дърпа CC снимки на пилотите от Wikipedia (Wikimedia Commons) и записва URL-а.
 * Best-effort — пилот без намерена снимка просто се пропуска (остава монограмата).
 */
class FetchDriverPhotosCommand extends Command
{
    protected $signature = 'drivers:fetch-photos
        {--sleep=1500 : Пауза в милисекунди между заявките (щади Wikipedia)}
        {--refresh : Презарежда и за пилотите, които вече имат снимка (презаписва)}
        {--all : Обхожда ВСИЧКИ канонични пилоти (легендите), не само текущия сезон}';

    protected $description = 'Дърпа CC снимки на пилотите от Wikipedia (Wikimedia Commons).';

    public function handle(DriverPhotoFetcher $fetcher): int
    {
        return $this->option('all')
            ? $this->fetchAllCanonical($fetcher)
            : $this->fetchCurrentSeason($fetcher);
    }

    private function fetchCurrentSeason(DriverPhotoFetcher $fetcher): int
    {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        $refresh = (bool) $this->option('refresh');

        $drivers = $season->drivers()
            ->when(! $refresh, fn ($q) => $q->whereNull('photo_url'))
            ->with('constructor')
            ->orderBy('last_name')
            ->get();

        $sleepMs = max(0, (int) $this->option('sleep'));
        $found = 0;

        $this->info("Търся снимки за {$drivers->count()} пилота (сезон {$season->year})...");

        foreach ($drivers as $driver) {
            $url = $fetcher->fetch($driver);

            if ($url !== null) {
                $driver->update(['photo_url' => $url]);
                $driver->canonical?->update(['photo_url' => $url]); // синхронизирай каноничния запис
                $found++;
                $this->line("  ✓ {$driver->fullName()}");
            } else {
                $this->line("  – {$driver->fullName()} (няма снимка)");
            }

            $this->pause($sleepMs);
        }

        $this->info("Готово: {$found}/{$drivers->count()} пилота със снимка.");

        return self::SUCCESS;
    }

    /**
     * Обхожда всички канонични пилоти (легенди). За всеки взима най-новия per-season
     * запис като контекст и записва URL-а на photo_url на каноничния запис.
     */
    private function fetchAllCanonical(DriverPhotoFetcher $fetcher): int
    {
        $refresh = (bool) $this->option('refresh');
        $sleepMs = max(0, (int) $this->option('sleep'));

        $query = DriverCanonical::query()->when(! $refresh, fn ($q) => $q->whereNull('photo_url'));
        $total = $query->count();

        $found = 0;
        $missing = 0;

        $this->info("Търся снимки за {$total} канонични пилота (≈".ceil($total * $sleepMs / 60000).' мин)...');

        $query->orderBy('last_name')->chunkById(50, function ($canonicals) use ($fetcher, $sleepMs, &$found, &$missing) {
            foreach ($canonicals as $canonical) {
                $driver = $canonical->seasons()->with('constructor')->orderByDesc('season_id')->first();

                if ($driver === null) {
                    $missing++;

                    continue;
                }

                $url = $fetcher->fetch($driver);

                if ($url !== null) {
                    $canonical->update(['photo_url' => $url]);
                    $found++;
                } else {
                    $missing++;
                }

                $this->pause($sleepMs);
            }
        });

        $this->info("Готово: {$found} със снимка, {$missing} без (остават с монограма).");

        return self::SUCCESS;
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
