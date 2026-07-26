<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\F2Race;
use App\Models\F2Season;
use App\Services\F2\F2WikipediaSync;
use Illuminate\Console\Command;

/**
 * Синхрон на F2 от Wikipedia — САМО за история.
 *
 * Основният източник е официалният API (`f2:sync`): и четирите сесии, точни
 * времена и готови класирания, минути след сесията. Wikipedia остава само
 * защото API-то не покрива сезоните преди 2026 г.
 *
 * Затова командата е извън разписанието и иска изричен `--historical`:
 * пусната върху текущия сезон, тя ще презапише пресните данни от API-то с
 * по-бедни и по-стари.
 */
class SyncF2WikipediaCommand extends Command
{
    /** Първата година, покрита от официалния API. */
    private const API_COVERAGE_FROM = 2026;

    protected $signature = 'f2:sync-wikipedia
        {--historical : Задължителен — потвърждава, че това е ръчен синхрон на стар сезон}
        {--force : Позволява синхрон и на сезони, покрити от официалния API}
        {--year= : Конкретна година (напр. 2024) или "all"}
        {--since=2025 : Начална година при --year=all}
        {--rebuild : Изтрива F2 race данните преди синхрон (внимателно)}';

    protected $description = 'Синхронизира стари F2 сезони от Wikipedia (само преди 2026 — текущите идват от f2:sync).';

    public function handle(F2WikipediaSync $sync): int
    {
        if (! $this->option('historical')) {
            $this->error('Основният източник за F2 вече е официалният API — пусни `f2:sync`.');
            $this->line('Ако наистина искаш да презаредиш стар сезон от Wikipedia, добави --historical.');

            return self::FAILURE;
        }

        $current = (int) now()->year;
        $yearOpt = $this->option('year');

        $years = match (true) {
            $yearOpt === 'all' => range((int) $this->option('since'), $current),
            $yearOpt !== null => [(int) $yearOpt],
            default => range((int) $this->option('since'), $current),
        };

        $covered = array_filter($years, fn (int $year): bool => $year >= self::API_COVERAGE_FROM);

        // Изричен флаг, не интерактивно потвърждение: командата трябва да е
        // използваема и от скрипт, а `confirm()` под cron виси до таймаут.
        if ($covered !== [] && ! $this->option('force')) {
            $this->error(implode(', ', $covered).' се покрива(т) от официалния API (`f2:sync`).');
            $this->line('Синхронът от Wikipedia би презаписал по-пресните данни с по-бедни.');
            $this->line('Ако наистина това искаш, добави --force.');

            return self::FAILURE;
        }

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
