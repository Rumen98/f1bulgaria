<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\Jolpica\SeasonSyncService;
use Illuminate\Console\Command;

class SyncSeasonCommand extends Command
{
    protected $signature = 'f1:sync-season {year? : Година на сезона (по подразбиране текущата календарна година)}';

    protected $description = 'Пълен синхрон на сезон от Jolpica: конструктори, пилоти и календар.';

    public function handle(SeasonSyncService $service): int
    {
        $year = (int) ($this->argument('year') ?? now()->year);

        $this->info("Синхронизирам сезон {$year} от Jolpica...");

        try {
            $stats = $service->sync($year);
        } catch (\Throwable $e) {
            $this->error("Синхронът се провали: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->markCurrentIfNewest($year);

        $this->table(
            ['Конструктори', 'Пилоти', 'Състезания'],
            [[$stats['constructors'], $stats['drivers'], $stats['races']]],
        );

        $this->info('Готово.');

        return self::SUCCESS;
    }

    /**
     * Маркира сезона като текущ, ако е най-новата синхронизирана година.
     */
    private function markCurrentIfNewest(int $year): void
    {
        $maxYear = (int) Season::query()->max('year');

        if ($year >= $maxYear) {
            Season::query()->where('year', '!=', $year)->update(['is_current' => false]);
            Season::query()->where('year', $year)->update(['is_current' => true]);
        }
    }
}
