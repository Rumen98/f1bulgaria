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
 * Успешно обогатените се публикуват автоматично (auto_published) и веднага
 * получават пълна статия (writeFullArticle) — модерацията е постфактум през
 * Filament (Reject). Грешка в един елемент се логва и не спира batch-а.
 */
class NewsEnricher
{
    public function __construct(
        private readonly NewsClassifier $classifier,
        private readonly NewsImageResolver $imageResolver,
        private readonly SourceArticleFetcher $sourceFetcher,
        private readonly IndexNowPinger $indexNow,
    ) {}

    /**
     * @param  Closure(TeamNewsItem, string, int, int, ?string): void|null  $onItem  Прогрес след всеки
     *                                                                               елемент: (елемент, изход published|duplicate|failed, пореден, общо, грешка).
     * @return array{processed:int, success:int, failed:int, duplicates:int, articles_failed:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
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
            'articles_failed' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'errors' => [],
        ];

        $sleepMs = (int) config('news.enrich_sleep_ms', 500);
        $total = $items->count();

        /** @var array<int, string> $publishedUrls */
        $publishedUrls = [];

        foreach ($items as $item) {
            $stats['processed']++;
            $outcome = 'failed';
            $error = null;

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
                    $publishedUrls[] = route('news.show', $item->slug);

                    // Пълната статия се пише веднага след публикацията. Грешка
                    // тук не проваля елемента (той вече е публикуван) — часовият
                    // news:generate-articles pass ще я допише.
                    try {
                        $articleUsage = $this->writeFullArticle($item);
                        $stats['input_tokens'] += $articleUsage['input_tokens'];
                        $stats['output_tokens'] += $articleUsage['output_tokens'];
                    } catch (Throwable $e) {
                        $stats['articles_failed']++;
                        $stats['errors'][] = "item #{$item->id} (статия): {$e->getMessage()}";
                        Log::warning("Full article generation failed for item [{$item->id}]: {$e->getMessage()}");
                    }
                }

                $stats['input_tokens'] += $result->tokenUsage['input_tokens'];
                $stats['output_tokens'] += $result->tokenUsage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $error = $e->getMessage();
                $stats['errors'][] = "item #{$item->id}: {$error}";
                Log::warning("News enrich failed for item [{$item->id}]: {$error}");
            }

            $onItem?->__invoke($item, $outcome, $stats['processed'], $total, $error);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        // Едно push уведомление за целия batch — Bing/Yandex научават веднага,
        // вместо да чакат следващото обхождане на sitemap-а.
        $this->indexNow->ping($publishedUrls);

        return $stats;
    }

    /**
     * Генерира разширена българска статия (full_article_bg + анализ + факти) за
     * одобрените новини, които още нямат такава. Грешка в един елемент се логва
     * и не спира batch-а.
     *
     * @param  Closure(TeamNewsItem, string, int, int, ?string): void|null  $onItem  Прогрес след всеки
     *                                                                               елемент: (елемент, изход generated|failed, пореден, общо, грешка).
     * @return array{processed:int, success:int, failed:int, input_tokens:int, output_tokens:int, errors:array<int, string>}
     */
    public function generateExtendedArticles(int $limit = 10, ?Closure $onItem = null): array
    {
        $items = TeamNewsItem::query()
            ->whereIn('status', collect(NewsStatus::publiclyVisible())->map->value->all())
            ->whereNull('full_article_bg')
            ->whereNotNull('title_bg')
            // Прясно публикуваните може още да са в inline генерация от паралелен
            // news:enrich — изчакваме ги, за да не плащаме заявката два пъти.
            ->where('updated_at', '<', now()->subMinutes(10))
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
            $error = null;

            try {
                $usage = $this->writeFullArticle($item);

                $stats['success']++;
                $outcome = 'generated';
                $stats['input_tokens'] += $usage['input_tokens'];
                $stats['output_tokens'] += $usage['output_tokens'];
            } catch (Throwable $e) {
                $stats['failed']++;
                $error = $e->getMessage();
                $stats['errors'][] = "item #{$item->id}: {$error}";
                Log::warning("News article generation failed for item [{$item->id}]: {$error}");
            }

            $onItem?->__invoke($item, $outcome, $stats['processed'], $total, $error);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    /**
     * Тегли пълния текст на оригинала (реални факти/резултати; при блокиран
     * източник — само RSS откъса) и генерира пълната българска статия.
     *
     * @return array{input_tokens:int, output_tokens:int}
     *
     * @throws Throwable
     */
    private function writeFullArticle(TeamNewsItem $item): array
    {
        $content = $this->classifier->generateFullArticle($item, $this->sourceFetcher->fetch($item));

        $item->update([
            'full_article_bg' => $content->fullArticleBg,
            'our_analysis_bg' => $content->analysisBg,
            'key_facts' => $content->keyFacts,
        ]);

        return $content->tokenUsage;
    }
}
