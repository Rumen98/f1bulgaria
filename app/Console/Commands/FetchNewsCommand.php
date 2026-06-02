<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsSource;
use App\Services\News\NewsAggregationService;
use Illuminate\Console\Command;

class FetchNewsCommand extends Command
{
    protected $signature = 'news:fetch {--source= : ID на конкретен източник (по подразбиране — всички активни)}';

    protected $description = 'Взема RSS/Atom feed-овете на новинарските източници и записва новите елементи.';

    public function handle(NewsAggregationService $service): int
    {
        if ($sourceId = $this->option('source')) {
            $source = TeamNewsSource::query()->find($sourceId);

            if ($source === null) {
                $this->error("Източник с ID {$sourceId} не съществува.");

                return self::FAILURE;
            }

            $stats = [$service->aggregateOne($source)];
        } else {
            $stats = $service->aggregateAll();
        }

        if ($stats === []) {
            $this->info('Няма активни източници за обхождане.');

            return self::SUCCESS;
        }

        $this->table(
            ['Източник', 'Извлечени', 'Нови', 'Дубликати', 'Грешка'],
            array_map(fn (array $row) => [
                $row['source'],
                $row['fetched'],
                $row['new'],
                $row['duplicates'],
                $row['errors'] ?? '—',
            ], $stats),
        );

        return self::SUCCESS;
    }
}
