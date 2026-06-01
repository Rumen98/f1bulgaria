<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\Badges\BadgeService;
use App\Services\Jolpica\ResultSyncService;
use Illuminate\Console\Command;

class SyncResultsCommand extends Command
{
    protected $signature = 'f1:sync-results {race? : ID на състезанието (по подразбиране — последното изминало)}';

    protected $description = 'Синхрон на резултатите от състезание, точкуване на прогнозите и присъждане на значки.';

    public function handle(ResultSyncService $sync, BadgeService $badges): int
    {
        $race = $this->resolveRace();

        if ($race === null) {
            $this->warn('Няма състезание за синхрон.');

            return self::SUCCESS;
        }

        $this->info("Синхронизирам резултати за: {$race->name} (кръг {$race->round})...");

        try {
            $stats = $sync->sync($race);
        } catch (\Throwable $e) {
            $this->error("Синхронът се провали: {$e->getMessage()}");

            return self::FAILURE;
        }

        $newBadges = $badges->evaluateForRace($race->fresh());

        $this->table(
            ['Резултати', 'Точкувани прогнози', 'Нови значки'],
            [[$stats['results'], $stats['scored'], $newBadges]],
        );

        $this->info('Готово.');

        return self::SUCCESS;
    }

    private function resolveRace(): ?Race
    {
        $id = $this->argument('race');

        if ($id !== null) {
            return Race::query()->find($id);
        }

        // Последното изминало състезание без синхронизирани резултати; ако всички
        // имат резултати — последното изминало изобщо (за повторен синхрон).
        return Race::query()
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '<=', now())
            ->whereDoesntHave('results')
            ->orderByDesc('race_datetime_utc')
            ->first()
            ?? Race::query()
                ->whereNotNull('race_datetime_utc')
                ->where('race_datetime_utc', '<=', now())
                ->orderByDesc('race_datetime_utc')
                ->first();
    }
}
