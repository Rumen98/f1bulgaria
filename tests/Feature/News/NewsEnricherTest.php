<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Services\News\Llm\LlmException;
use App\Services\News\Llm\NewsArticleContent;
use App\Services\News\Llm\NewsClassificationResult;
use App\Services\News\Llm\NewsClassifier;
use App\Services\News\NewsEnricher;
use App\Services\News\NewsImageResolver;
use App\Services\News\SourceArticleFetcher;

beforeEach(function () {
    // Без реална пауза между заявките в тестовете.
    config()->set('news.enrich_sleep_ms', 0);
});

function classResult(int $in = 10, int $out = 20, ?int $duplicateOfId = null): NewsClassificationResult
{
    return new NewsClassificationResult(
        titleBg: 'Българско заглавие',
        summaryBg: 'Българско резюме на новината.',
        classification: NewsClassification::Race,
        constructorId: null,
        importanceScore: 3,
        rawResponse: '{}',
        tokenUsage: ['input_tokens' => $in, 'output_tokens' => $out],
        duplicateOfId: $duplicateOfId,
    );
}

it('отхвърля автоматично крос-източниковите дубликати', function () {
    $original = TeamNewsItem::factory()->create();
    $duplicate = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')
        ->andReturn(classResult(duplicateOfId: $original->id)));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['duplicates'])->toBeGreaterThanOrEqual(1)
        ->and($duplicate->fresh()->status)->toBe(NewsStatus::Rejected);
});

it('попълва полетата на pending items и ги публикува автоматично', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats)->toMatchArray(['processed' => 2, 'success' => 2, 'failed' => 0]);

    $item = TeamNewsItem::first();
    expect($item->title_bg)->toBe('Българско заглавие')
        ->and($item->classification)->toBe(NewsClassification::Race)
        ->and($item->importance_score)->toBe(3)
        ->and($item->status)->toBe(NewsStatus::AutoPublished); // публикува се без ръчно одобрение
});

it('грешка в един item не спира batch-а', function () {
    $items = TeamNewsItem::factory()->count(3)->create(['title_bg' => null, 'classification' => null]);
    $badId = $items[1]->id;

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')
        ->andReturnUsing(function (TeamNewsItem $item) use ($badId) {
            if ($item->id === $badId) {
                throw new LlmException('boom');
            }

            return classResult();
        }));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['processed'])->toBe(3)
        ->and($stats['success'])->toBe(2)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['errors'])->toHaveCount(1)
        ->and(TeamNewsItem::find($badId)->title_bg)->toBeNull()
        ->and(TeamNewsItem::find($badId)->status)->toBe(NewsStatus::Pending); // проваленият не се публикува
});

it('грешка при featured_image оставя елемента pending, а news:publish-pending го досъбира', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));
    test()->mock(NewsImageResolver::class, fn ($m) => $m->shouldReceive('resolve')
        ->andThrow(new RuntimeException('image boom')));

    $stats = app(NewsEnricher::class)->enrichPending();

    // Преводът е записан, но публикацията (финалната стъпка) не е минала.
    $item->refresh();
    expect($stats['failed'])->toBe(1)
        ->and($item->title_bg)->toBe('Българско заглавие')
        ->and($item->status)->toBe(NewsStatus::Pending);

    // Осигурителната мрежа от scheduler-а го публикува на следващия pass.
    $this->artisan('news:publish-pending')->assertSuccessful();

    expect($item->fresh()->status)->toBe(NewsStatus::AutoPublished);
});

it('сумира token usage', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult(10, 20)));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['input_tokens'])->toBe(20)
        ->and($stats['output_tokens'])->toBe(40);
});

it('не пипа items, които вече са обогатени', function () {
    $enriched = TeamNewsItem::factory()->create([
        'title_bg' => 'Вече преведено',
        'classification' => NewsClassification::Driver->value,
    ]);
    TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->once()->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['processed'])->toBe(1) // само неенрихнатият
        ->and($enriched->fresh()->title_bg)->toBe('Вече преведено');
});

it('спазва --limit', function () {
    TeamNewsItem::factory()->count(5)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));

    $stats = app(NewsEnricher::class)->enrichPending(2);

    expect($stats['processed'])->toBe(2);
});

it('подава прогрес callback за всеки обработен елемент', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(classResult()));

    $progress = [];
    app(NewsEnricher::class)->enrichPending(50, function (TeamNewsItem $item, string $outcome, int $position, int $total) use (&$progress) {
        $progress[] = [$position, $total, $outcome];
    });

    expect($progress)->toBe([[1, 2, 'published'], [2, 2, 'published']]);
});

it('подава пълния текст на оригинала към генератора на статии', function () {
    TeamNewsItem::factory()->approved()->create(['full_article_bg' => null]);

    test()->mock(SourceArticleFetcher::class, fn ($m) => $m->shouldReceive('fetch')
        ->once()->andReturn('Пълен текст с резултатите от квалификацията.'));

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldReceive('generateFullArticle')
        ->once()
        ->withArgs(fn (TeamNewsItem $item, ?string $sourceText) => $sourceText === 'Пълен текст с резултатите от квалификацията.')
        ->andReturn(new NewsArticleContent(
            fullArticleBg: 'Пълна статия.',
            keyFacts: ['Факт'],
            analysisBg: 'Анализ.',
            tokenUsage: ['input_tokens' => 1, 'output_tokens' => 2],
        )));

    $stats = app(NewsEnricher::class)->generateExtendedArticles();

    expect($stats['success'])->toBe(1)
        ->and(TeamNewsItem::first()->full_article_bg)->toBe('Пълна статия.');
});
