<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LiveTiming\OpenF1SessionSync;
use App\Services\Telegram\F1ChannelEnqueuer;
use Illuminate\Console\Command;

/**
 * Разписание на уикенда + класации от тренировките и спринт квалификацията.
 *
 * Разписанието е половината смисъл на командата: `race_sessions` стоеше празна
 * от създаването си, а началната страница я чете, за да разбере дали тече
 * състезателен уикенд. Без нея сайтът остава в режим „предстоящо" дори след
 * финала.
 */
class SyncF1SessionsCommand extends Command
{
    protected $signature = 'f1:sync-sessions
        {--year= : Конкретна година (по подразбиране текущата)}
        {--no-channel : Само синхрон, без поставяне в опашката на канала}';

    protected $description = 'Синхронизира разписанието на сесиите и класациите от тренировките (OpenF1).';

    public function handle(OpenF1SessionSync $sync, F1ChannelEnqueuer $enqueuer): int
    {
        $year = $this->option('year') !== null ? (int) $this->option('year') : (int) now()->year;

        $stats = $sync->syncSeason($year);

        $this->table(
            ['Сесии', 'Резултати', 'Несъпоставени кръгове'],
            [[$stats['sessions'], $stats['results'], $stats['skipped']]],
        );

        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        if (! $this->option('no-channel')) {
            $queued = $enqueuer->enqueuePending();

            $this->line("В опашката на канала: {$queued['queued']} нови, {$queued['updated']} обновени");

            foreach ($queued['errors'] as $error) {
                $this->warn($error);
            }
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
