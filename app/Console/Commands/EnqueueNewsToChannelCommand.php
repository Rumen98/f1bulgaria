<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\NewsChannelEnqueuer;
use Illuminate\Console\Command;

/**
 * Отделна команда, а не част от news:publish-pending: публикуването на сайта
 * и публикуването в канала са различни решения. Каналът може да е изключен,
 * прагът да се вдигне или изпращането да се провали — нищо от това не бива
 * да разклаща новинарския pipeline.
 */
class EnqueueNewsToChannelCommand extends Command
{
    protected $signature = 'channel:enqueue-news';

    protected $description = 'Поставя най-важните публикувани новини в опашката към Telegram канала.';

    public function handle(NewsChannelEnqueuer $enqueuer): int
    {
        $stats = $enqueuer->enqueuePending();

        $this->info(sprintf(
            'Новини в опашката: %d нови, %d обновени (праг: %d).',
            $stats['queued'],
            $stats['updated'],
            (int) config('channel.news_min_importance', 4),
        ));

        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
