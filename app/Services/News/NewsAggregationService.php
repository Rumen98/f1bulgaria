<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use Illuminate\Support\Facades\Log;

/**
 * Обхожда активните източници, взема feed-овете през RssFetcher, дедупликира
 * по external_url (и external_guid като вторичен ключ) и записва новите елементи
 * със статус "pending". Грешка в един източник не спира останалите.
 */
class NewsAggregationService
{
    public function __construct(
        private readonly RssFetcher $fetcher,
        private readonly TsolovDetector $tsolov,
    ) {}

    /**
     * @return array<int, array{source:string, fetched:int, new:int, duplicates:int, errors:string|null}>
     */
    public function aggregateAll(): array
    {
        return TeamNewsSource::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TeamNewsSource $source) => $this->aggregateOne($source))
            ->all();
    }

    /**
     * @return array{source:string, fetched:int, new:int, duplicates:int, errors:string|null}
     */
    public function aggregateOne(TeamNewsSource $source): array
    {
        $stats = [
            'source' => $source->name,
            'fetched' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => null,
        ];

        try {
            $items = $this->fetcher->fetch($source);
        } catch (NewsFetchException $e) {
            Log::warning("News fetch failed for source [{$source->id}] {$source->name}: {$e->getMessage()}");
            $stats['errors'] = $e->getMessage();

            return $stats;
        }

        $seenUrls = [];
        $seenGuids = [];

        foreach ($items as $item) {
            $stats['fetched']++;

            if ($this->isDuplicate($item, $seenUrls, $seenGuids)) {
                $stats['duplicates']++;

                continue;
            }

            TeamNewsItem::query()->create([
                'source_id' => $source->id,
                // Глобален източник → null (LLM ще класифицира в стъпка 3);
                // отборен източник → знаем конструктора веднага.
                'constructor_id' => $source->constructor_id,
                'external_url' => $item->externalUrl,
                'external_guid' => $item->externalGuid,
                'title_original' => $item->titleOriginal,
                'content_snippet' => $item->contentSnippet,
                // Разпознава се тук, върху оригиналния текст, преди LLM-ът да
                // го е пипал: детерминистично, безплатно и работи дори когато
                // доставчикът на модела е паднал.
                'is_tsolov' => $this->tsolov->matches($item->titleOriginal, $item->contentSnippet),
                'published_at' => $item->publishedAt,
                'status' => NewsStatus::Pending->value,
            ]);

            $seenUrls[$item->externalUrl] = true;
            if ($item->externalGuid !== null) {
                $seenGuids[$item->externalGuid] = true;
            }

            $stats['new']++;
        }

        $source->forceFill(['last_fetched_at' => now()])->save();

        return $stats;
    }

    /**
     * Дубликат ли е — спрямо вече видяното в този batch или в базата.
     *
     * @param  array<string, bool>  $seenUrls
     * @param  array<string, bool>  $seenGuids
     */
    private function isDuplicate(ParsedFeedItem $item, array $seenUrls, array $seenGuids): bool
    {
        if (isset($seenUrls[$item->externalUrl])) {
            return true;
        }

        if ($item->externalGuid !== null && isset($seenGuids[$item->externalGuid])) {
            return true;
        }

        return TeamNewsItem::query()
            ->where('external_url', $item->externalUrl)
            ->when(
                $item->externalGuid !== null,
                fn ($query) => $query->orWhere('external_guid', $item->externalGuid),
            )
            ->exists();
    }
}
