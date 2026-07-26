<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use Illuminate\Console\Command;

class PublishPendingNewsCommand extends Command
{
    protected $signature = 'news:publish-pending';

    protected $description = 'Публикува (auto_published) всички обогатени pending новини — backlog и застояли след частичен enrich.';

    public function handle(): int
    {
        $published = TeamNewsItem::query()
            ->where('status', NewsStatus::Pending->value)
            ->whereNotNull('title_bg')
            ->whereNotNull('classification')
            ->update(['status' => NewsStatus::AutoPublished->value]);

        $this->info("Публикувани новини: {$published}.");

        return self::SUCCESS;
    }
}
