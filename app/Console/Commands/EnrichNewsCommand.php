<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeamNewsItem;
use App\Services\News\NewsEnricher;
use Illuminate\Console\Command;

class EnrichNewsCommand extends Command
{
    protected $signature = 'news:enrich {--limit=50 : Максимален брой новини за обработка}';

    protected $description = 'Класифицира и превежда pending новините на български чрез Claude (LLM).';

    public function handle(NewsEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');

        $stats = $enricher->enrichPending($limit, function (TeamNewsItem $item, string $outcome, int $position, int $total, ?string $error): void {
            $label = match ($outcome) {
                'published' => 'публикувана',
                'duplicate' => 'дубликат',
                default => 'ГРЕШКА ('.($error ?? '?').')',
            };

            $this->line("[{$position}/{$total}] #{$item->id} {$label}: ".($item->title_bg ?? $item->title_original));
        });

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
