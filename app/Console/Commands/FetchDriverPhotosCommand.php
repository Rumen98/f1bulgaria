<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\Drivers\DriverPhotoFetcher;
use Illuminate\Console\Command;

/**
 * Дърпа CC снимки на пилотите от текущия сезон от Wikipedia и записва URL-а в
 * drivers.photo_url. Best-effort — пилот без намерена снимка просто се пропуска.
 */
class FetchDriverPhotosCommand extends Command
{
    protected $signature = 'drivers:fetch-photos {--sleep=1500 : Пауза в милисекунди между заявките (щади Wikipedia)}';

    protected $description = 'Дърпа CC снимки на пилотите от текущия сезон от Wikipedia (Wikimedia Commons).';

    public function handle(DriverPhotoFetcher $fetcher): int
    {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        $drivers = $season->drivers()->orderBy('last_name')->get();
        $sleepMs = max(0, (int) $this->option('sleep'));
        $found = 0;

        $this->info("Търся снимки за {$drivers->count()} пилота (сезон {$season->year})...");

        foreach ($drivers as $driver) {
            $url = $fetcher->fetch($driver);

            if ($url !== null) {
                $driver->update(['photo_url' => $url]);
                $found++;
                $this->line("  ✓ {$driver->fullName()}");
            } else {
                $this->line("  – {$driver->fullName()} (няма снимка)");
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Готово: {$found}/{$drivers->count()} пилота със снимка.");

        return self::SUCCESS;
    }
}
