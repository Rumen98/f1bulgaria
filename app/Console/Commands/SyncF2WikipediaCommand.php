<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\F2Race;
use App\Models\F2Season;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Console\Command;

/**
 * Синхронизира F2 данни от Wikipedia. По подразбиране от 2025 нагоре.
 */
class SyncF2WikipediaCommand extends Command
{
    protected $signature = 'f2:sync-wikipedia
        {--year= : Конкретна година (напр. 2026) или "all"}
        {--since=2025 : Начална година при --year=all}
        {--rebuild : Изтрива F2 race данните преди синхрон (внимателно)}';

    protected $description = 'Синхронизира F2 кръгове и резултати от Wikipedia.';

    public function handle(F2WikipediaSync $sync): int
    {
        $current = (int) now()->year;
        $yearOpt = $this->option('year');

        $years = match (true) {
            $yearOpt === 'all' => range((int) $this->option('since'), $current),
            $yearOpt !== null => [(int) $yearOpt],
            default => range((int) $this->option('since'), $current),
        };

        if ($this->option('rebuild')) {
            // Само за синхронизираните години — да не трием чужди сезони.
            $this->warn('Изтривам F2 race данните за: '.implode(', ', $years).'…');
            $seasonIds = F2Season::query()->whereIn('year', $years)->pluck('id');
            F2Race::query()->whereIn('f2_season_id', $seasonIds)->delete(); // каскадно трие сесии + резултати
        }

        $this->info('🏁 Синхрон на F2 от Wikipedia');

        foreach ($years as $year) {
            $this->line("Сезон {$year}:");

            $result = $sync->syncYear($year, function (int $roundNo, string $title, bool $ok) {
                $this->line(sprintf('    [%d] %s — %s', $roundNo, $title, $ok ? '✓' : '⏭ пропуснат (липсва/празен)'));
            });

            if ($result['season'] === null) {
                $this->warn("  Сезонната страница липсва — пропускам {$year}.");

                continue;
            }

            $this->line("  ✓ Отбори: {$result['teams']}, Пилоти: {$result['drivers']}, "
                ."Кръгове: {$result['rounds']}, Races: {$result['races']}, Резултати: {$result['results']}"
                .($result['skipped'] ? ", пропуснати кръгове: {$result['skipped']}" : ''));
        }

        $this->info('✓ Готово.');

        return self::SUCCESS;
    }
}
