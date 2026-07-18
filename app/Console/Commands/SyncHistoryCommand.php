<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\Jolpica\JolpicaRateLimitException;
use App\Services\Jolpica\ResultSyncService;
use App\Services\Jolpica\SeasonSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Bulk синхрон на исторически сезони (сезон + резултати за всяко изминало
 * състезание). Устойчив на грешки — при провал на сезон или състезание
 * продължава със следващите и докладва обобщение накрая. При ударен rate
 * limit на Jolpica изчаква прозореца и повтаря същата стъпка, вместо да
 * гори останалите сезони.
 */
class SyncHistoryCommand extends Command
{
    /** Максимален брой изчаквания на rate limit за една стъпка, преди отказ. */
    private const MAX_RATE_LIMIT_WAITS = 8;

    protected $signature = 'f1:sync-history
        {from : Първа година (вкл.)}
        {to : Последна година (вкл.)}
        {--skip= : Години за прескачане, разделени със запетая (напр. 2024,2026)}
        {--throttle=300 : Пауза в милисекунди между състезанията (щади Jolpica)}
        {--cooldown=600 : Секунди изчакване при ударен rate limit, преди повторен опит}';

    protected $description = 'Bulk синхрон на исторически сезони (сезон + резултати) от Jolpica. Продължава при грешка.';

    public function handle(SeasonSyncService $seasons, ResultSyncService $results): int
    {
        $from = (int) $this->argument('from');
        $to = (int) $this->argument('to');
        $throttleMs = max(0, (int) $this->option('throttle'));
        $cooldown = max(1, (int) $this->option('cooldown'));
        $skip = $this->skipYears();

        if ($from > $to) {
            $this->error('Първата година трябва да е <= последната.');

            return self::FAILURE;
        }

        /** @var array<int, array{season:string, races:int, failed:int}> $summary */
        $summary = [];

        foreach (range($from, $to) as $year) {
            if (in_array($year, $skip, true)) {
                $this->line("⏭  Прескачам {$year}");

                continue;
            }

            $this->info("=== Сезон {$year} ===");

            try {
                $stats = $this->waitingOnRateLimit(fn (): array => $seasons->sync($year), $cooldown);
                $this->line("  Календар: {$stats['constructors']} конструктора, {$stats['drivers']} пилота, {$stats['races']} състезания");
            } catch (Throwable $e) {
                $this->error("  Сезон {$year} се провали: {$e->getMessage()}");
                $summary[$year] = ['season' => 'FAIL', 'races' => 0, 'failed' => 0];

                continue;
            }

            [$ok, $failed] = $this->syncSeasonResults($year, $results, $throttleMs, $cooldown);
            $summary[$year] = ['season' => 'OK', 'races' => $ok, 'failed' => $failed];
        }

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * Синхронизира резултатите за всички изминали състезания на сезона.
     *
     * @return array{0:int, 1:int} [успешни, провалени]
     */
    private function syncSeasonResults(int $year, ResultSyncService $results, int $throttleMs, int $cooldownSeconds): array
    {
        $races = Race::query()
            ->whereHas('season', fn ($q) => $q->where('year', $year))
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '<=', now())
            ->orderBy('round')
            ->get();

        if ($races->isEmpty()) {
            return [0, 0];
        }

        $ok = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($races->count());
        $bar->start();

        foreach ($races as $race) {
            try {
                $this->waitingOnRateLimit(fn () => $results->sync($race), $cooldownSeconds);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning("f1:sync-history — резултати за {$year} кръг {$race->round} се провалиха: {$e->getMessage()}");
            }

            $bar->advance();

            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine();

        return [$ok, $failed];
    }

    /**
     * Изпълнява $fn; при ударен rate limit изчаква cooldown-а и повтаря
     * същата стъпка (до MAX_RATE_LIMIT_WAITS изчаквания), за да не горим
     * останалите сезони, докато прозорецът на Jolpica е изчерпан.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $fn
     * @return TReturn
     */
    private function waitingOnRateLimit(callable $fn, int $cooldownSeconds): mixed
    {
        $waits = 0;

        while (true) {
            try {
                return $fn();
            } catch (JolpicaRateLimitException $e) {
                if (++$waits > self::MAX_RATE_LIMIT_WAITS) {
                    throw $e;
                }

                $this->newLine();
                $this->warn(sprintf(
                    '  Rate limit на Jolpica — изчаквам %d сек (изчакване %d/%d)…',
                    $cooldownSeconds, $waits, self::MAX_RATE_LIMIT_WAITS,
                ));
                Sleep::for($cooldownSeconds)->seconds();
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function skipYears(): array
    {
        return collect(explode(',', (string) $this->option('skip')))
            ->map(fn ($y) => (int) trim($y))
            ->filter()
            ->all();
    }

    /**
     * @param  array<int, array{season:string, races:int, failed:int}>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->info('=== Обобщение ===');

        $rows = [];
        foreach ($summary as $year => $row) {
            $rows[] = [$year, $row['season'], $row['races'], $row['failed'] ?: '—'];
        }

        $this->table(['Сезон', 'Календар', 'Синхр. състезания', 'Провалени'], $rows);
    }
}
