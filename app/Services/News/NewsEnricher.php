<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\NewsClassifier;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обогатява pending новините чрез LLM класификатора: попълва title_bg,
 * summary_bg, classification, constructor_id и importance_score.
 *
 * Успешно обогатените се публикуват автоматично (auto_published) — модерацията
 * е постфактум през Filament (Reject). Грешка в един елемент се логва и не
 * спира batch-а.
 */
class NewsEnricher
{
    public function __construct(
        private readonly NewsClassifier $classifier,
        private readonly NewsImageResolver $imageResolver,
        private readonly SourceArticleFetcher $sourceFetcher,
    ) {}

    /**
     * @param  Closure(TeamNewsItem, string, int, int): void|null  $onItem  Прогрес след
     *                                                                      всеки елемент: (елемент, изход published|duplicate|failed, пореден, общо).
     * @return array{processed:int, success:int, failed:int, duplicates:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
     */
    public function enrichPending(int $limit = 50, ?Closure $onItem = null): array
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
            'duplicates' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'errors' => [],
        ];

        $sleepMs = (int) config('news.enrich_sleep_ms', 500);
        $total = $items->count();

        foreach ($items as $item) {
            $stats['processed']++;
            $outcome = 'failed';

            try {
                $result = $this->classifier->classify($item);

                // Крос-източников дубликат (същата история от друг сайт) —
                // отхвърля се автоматично, за да не излиза два пъти на сайта.
                if ($result->duplicateOfId !== null) {
                    $item->update([
                        'title_bg' => $result->titleBg,
                        'summary_bg' => $result->summaryBg,
                        'classification' => $result->classification->value,
                        'status' => NewsStatus::Rejected->value,
                    ]);

                    Log::info("News item [{$item->id}] отхвърлен като дубликат на [{$result->duplicateOfId}].");
                    $stats['duplicates']++;
                    $outcome = 'duplicate';
                } else {
                    $item->update([
                        'title_bg' => $result->titleBg,
                        'summary_bg' => $result->summaryBg,
                        'classification' => $result->classification->value,
                        'constructor_id' => $result->constructorId ?? $item->constructor_id,
                        'importance_score' => $result->importanceScore,
                    ]);

                    // Визуален header — резолвва се от вече попълнените класификация/отбор.
                    // Публикуването е финалната стъпка: ако нещо гръмне преди нея,
                    // елементът остава pending и news:publish-pending го досъбира.
                    $item->unsetRelation('constructor');
                    $item->update([
                        'featured_image' => $this->imageResolver->resolve($item),
                        'status' => NewsStatus::AutoPublished->value,
                    ]);

                    $stats['success']++;
                    $outcome = 'published';
                }

                $stats['input_tokens'] += $result->tokenUsage['input_tokens'];
                $stats['output_tokens'] += $result->tokenUsage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "item #{$item->id}: {$e->getMessage()}";
                Log::warning("News enrich failed for item [{$item->id}]: {$e->getMessage()}");
            }

            $onItem?->__invoke($item, $outcome, $stats['processed'], $total);

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
     * @param  Closure(TeamNewsItem, string, int, int): void|null  $onItem  Прогрес след
     *                                                                      всеки елемент: (елемент, изход generated|failed, пореден, общо).
     * @return array{processed:int, success:int, failed:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
     */
    public function generateExtendedArticles(int $limit = 10, ?Closure $onItem = null): array
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
        $total = $items->count();

        foreach ($items as $item) {
            $stats['processed']++;
            $outcome = 'failed';

            try {
                // Пълният текст на оригинала дава реалните факти/резултати;
                // при блокиран източник генерираме само от RSS откъса.
                $content = $this->classifier->generateFullArticle($item, $this->sourceFetcher->fetch($item));

                $item->update([
                    'full_article_bg' => $content->fullArticleBg,
                    'our_analysis_bg' => $content->analysisBg,
                    'key_facts' => $content->keyFacts,
                ]);

                $stats['success']++;
                $outcome = 'generated';
                $stats['input_tokens'] += $content->tokenUsage['input_tokens'];
                $stats['output_tokens'] += $content->tokenUsage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "item #{$item->id}: {$e->getMessage()}";
                Log::warning("News article generation failed for item [{$item->id}]: {$e->getMessage()}");
            }

            $onItem?->__invoke($item, $outcome, $stats['processed'], $total);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }
}
