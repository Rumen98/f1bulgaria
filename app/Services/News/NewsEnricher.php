<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\NewsClassifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обогатява pending новините чрез LLM класификатора: попълва title_bg,
 * summary_bg, classification, constructor_id и importance_score.
 *
 * Статусът ОСТАВА 'pending' — човешкият review е в стъпка 5. Грешка в един
 * елемент се логва и не спира batch-а.
 */
class NewsEnricher
{
    public function __construct(
        private readonly NewsClassifier $classifier,
        private readonly NewsImageResolver $imageResolver,
    ) {}

    /**
     * @return array{processed:int, success:int, failed:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
     */
    public function enrichPending(int $limit = 50): array
    {
        $items = TeamNewsItem::query()
            ->where('status', NewsStatus::Pending->value)
            ->where(function ($query) {
                $query->whereNull('title_bg')->orWhereNull('classification');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'errors' => [],
        ];

        $sleepMs = (int) config('news.enrich_sleep_ms', 500);

        foreach ($items as $item) {
            $stats['processed']++;

            try {
                $result = $this->classifier->classify($item);

                $item->update([
                    'title_bg' => $result->titleBg,
                    'summary_bg' => $result->summaryBg,
                    'classification' => $result->classification->value,
                    'constructor_id' => $result->constructorId ?? $item->constructor_id,
                    'importance_score' => $result->importanceScore,
                    // status НЕ се променя — остава pending за човешки review.
                ]);

                // Визуален header — резолвва се от вече попълнените класификация/отбор.
                $item->unsetRelation('constructor');
                $item->update(['featured_image' => $this->imageResolver->resolve($item)]);

                $stats['success']++;
                $stats['input_tokens'] += $result->tokenUsage['input_tokens'];
                $stats['output_tokens'] += $result->tokenUsage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "item #{$item->id}: {$e->getMessage()}";
                Log::warning("News enrich failed for item [{$item->id}]: {$e->getMessage()}");
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    /**
     * Генерира разширена българска статия (full_article_bg + анализ + факти) за
     * одобрените новини, които още нямат такава. Грешка в един елемент се логва
     * и не спира batch-а.
     *
     * @return array{processed:int, success:int, failed:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
     */
    public function generateExtendedArticles(int $limit = 10): array
    {
        $items = TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->whereNull('full_article_bg')
            ->whereNotNull('title_bg')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'errors' => [],
        ];

        $sleepMs = (int) config('news.enrich_sleep_ms', 500);

        foreach ($items as $item) {
            $stats['processed']++;

            try {
                $content = $this->classifier->generateFullArticle($item);

                $item->update([
                    'full_article_bg' => $content->fullArticleBg,
                    'our_analysis_bg' => $content->analysisBg,
                    'key_facts' => $content->keyFacts,
                ]);

                $stats['success']++;
                $stats['input_tokens'] += $content->tokenUsage['input_tokens'];
                $stats['output_tokens'] += $content->tokenUsage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "item #{$item->id}: {$e->getMessage()}";
                Log::warning("News article generation failed for item [{$item->id}]: {$e->getMessage()}");
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }
}
