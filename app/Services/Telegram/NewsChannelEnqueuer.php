<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostKind;
use App\Enums\ChannelQueueOutcome;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\Telegram\Formatters\NewsFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пълни опашката с най-важните новини.
 *
 * Прагът е същественото тук. Pipeline-ът публикува на всеки 30 минути и в
 * натоварен ден изкарва десетки статии; канал, който ги препредава всичките,
 * се заглушава от хората и повече не се отглушава. Затова минават само
 * новините с importance_score над `channel.news_min_importance` — скалата е
 * 1 до 5 и се задава от класификатора.
 */
class NewsChannelEnqueuer
{
    public function __construct(
        private readonly ChannelQueue $queue,
        private readonly NewsFormatter $formatter,
    ) {}

    /**
     * @return array{queued:int, updated:int, errors:array<int, string>}
     */
    public function enqueuePending(): array
    {
        $stats = ['queued' => 0, 'updated' => 0, 'errors' => []];

        $threshold = (int) config('channel.news_min_importance', 4);
        $cutoff = now()->subHours((int) config('channel.max_backfill_hours', 24));

        $items = TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map(fn (NewsStatus $s) => $s->value))
            ->where('importance_score', '>=', $threshold)
            ->whereNotNull('title_bg')
            // Датата на публикуване е тази на ИЗТОЧНИКА и може да е стара дори
            // за прясно взета статия. Прозорецът гледа кога сме я вписали ние.
            ->where('created_at', '>=', $cutoff)
            ->with('constructor')
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            try {
                $outcome = $this->queue->enqueue(
                    $item,
                    ChannelPostKind::News,
                    $this->formatter->format($item),
                    $item->created_at,
                );

                match ($outcome) {
                    ChannelQueueOutcome::Created => $stats['queued']++,
                    ChannelQueueOutcome::Updated => $stats['updated']++,
                    ChannelQueueOutcome::Unchanged => null,
                };
            } catch (Throwable $e) {
                $stats['errors'][] = "новина #{$item->id}: {$e->getMessage()}";
                Log::warning("Канал: неуспешно поставяне на новина [{$item->id}]: {$e->getMessage()}");
            }
        }

        return $stats;
    }
}
