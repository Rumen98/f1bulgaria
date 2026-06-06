<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\F2Race;
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
        if ($this->option('rebuild')) {
            $this->warn('Изтривам съществуващите F2 race данни…');
            F2Race::query()->delete(); // каскадно трие сесии + резултати
        }

        $current = (int) now()->year;
        $yearOpt = $this->option('year');

        $years = match (true) {
            $yearOpt === 'all' => range((int) $this->option('since'), $current),
            $yearOpt !== null => [(int) $yearOpt],
            default => range((int) $this->option('since'), $current),
        };

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
