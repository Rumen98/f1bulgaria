<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsEnricher;
use Illuminate\Console\Command;

class EnrichNewsCommand extends Command
{
    protected $signature = 'news:enrich {--limit=50 : Максимален брой новини за обработка}';

    protected $description = 'Класифицира и превежда pending новините на български чрез Claude (LLM).';

    public function handle(NewsEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');

        $stats = $enricher->enrichPending($limit);

        $this->table(
            ['Обработени', 'Успешни', 'Провалени', 'Input tokens', 'Output tokens'],
            [[
                $stats['processed'],
                $stats['success'],
                $stats['failed'],
                $stats['input_tokens'],
                $stats['output_tokens'],
            ]],
        );

        $totalTokens = $stats['input_tokens'] + $stats['output_tokens'];
        $this->info("Общо токени: {$totalTokens}");

        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
