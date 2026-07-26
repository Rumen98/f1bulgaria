<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\IndexNowPinger;
use Illuminate\Console\Command;

class PublishPendingNewsCommand extends Command
{
    protected $signature = 'news:publish-pending';

    protected $description = 'Публикува (auto_published) всички обогатени pending новини — backlog и застояли след частичен enrich.';

    public function handle(IndexNowPinger $indexNow): int
    {
        // Select преди update — иначе няма как да знаем кои редове сме
        // публикували, за да ги подадем на IndexNow.
        $items = TeamNewsItem::query()
            ->where('status', NewsStatus::Pending->value)
            ->whereNotNull('title_bg')
            ->whereNotNull('classification')
            ->get(['id', 'slug']);

        if ($items->isEmpty()) {
            $this->info('Публикувани новини: 0.');

            return self::SUCCESS;
        }

        TeamNewsItem::query()
            ->whereKey($items->pluck('id'))
            ->update(['status' => NewsStatus::AutoPublished->value]);

        $indexNow->ping($items->map(fn (TeamNewsItem $item) => route('news.show', $item->slug))->all());

        $this->info("Публикувани новини: {$items->count()}.");

        return self::SUCCESS;
    }
}
