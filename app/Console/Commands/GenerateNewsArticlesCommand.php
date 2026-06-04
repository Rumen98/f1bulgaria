<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsEnricher;
use Illuminate\Console\Command;

class GenerateNewsArticlesCommand extends Command
{
    protected $signature = 'news:generate-articles {--limit=10 : Максимален брой статии за генериране}';

    protected $description = 'Генерира разширени оригинални български статии (+анализ) за одобрените новини чрез Claude.';

    public function handle(NewsEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');

        $stats = $enricher->generateExtendedArticles($limit);

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
