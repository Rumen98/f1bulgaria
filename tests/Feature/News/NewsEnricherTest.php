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

    // Никакви реални HTTP заявки към external_url на factory елементите.
    test()->mock(SourceArticleFetcher::class, fn ($m) => $m->shouldReceive('fetch')->andReturn(null)->byDefault());
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

function articleContent(int $in = 5, int $out = 7): NewsArticleContent
{
    return new NewsArticleContent(
        fullArticleBg: 'Пълна статия.',
        keyFacts: ['Факт'],
        analysisBg: 'Анализ.',
        tokenUsage: ['input_tokens' => $in, 'output_tokens' => $out],
    );
}

it('отхвърля автоматично крос-източниковите дубликати', function () {
    $original = TeamNewsItem::factory()->create();
    $duplicate = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) use ($original) {
        $m->shouldReceive('classify')->andReturn(classResult(duplicateOfId: $original->id));
        // За дубликат не се хаби заявка за пълна статия.
        $m->shouldNotReceive('generateFullArticle');
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['duplicates'])->toBeGreaterThanOrEqual(1)
        ->and($duplicate->fresh()->status)->toBe(NewsStatus::Rejected);
});

it('попълва полетата на pending items, публикува ги и пише пълната статия веднага', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(classResult());
        $m->shouldReceive('generateFullArticle')->twice()->andReturn(articleContent());
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats)->toMatchArray(['processed' => 2, 'success' => 2, 'failed' => 0]);

    $item = TeamNewsItem::first();
    expect($item->title_bg)->toBe('Българско заглавие')
        ->and($item->classification)->toBe(NewsClassification::Race)
        ->and($item->importance_score)->toBe(3)
        ->and($item->status)->toBe(NewsStatus::AutoPublished) // публикува се без ръчно одобрение
        ->and($item->full_article_bg)->toBe('Пълна статия.'); // и статията идва веднага
});

it('провал при пълната статия не разваля публикацията', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(classResult());
        $m->shouldReceive('generateFullArticle')->andThrow(new LlmException('article boom'));
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    // Новината е публикувана; статията ще се допише от news:generate-articles.
    expect($stats)->toMatchArray(['success' => 1, 'failed' => 0])
        ->and($item->fresh()->status)->toBe(NewsStatus::AutoPublished)
        ->and($item->fresh()->full_article_bg)->toBeNull();
});

it('грешка в един item не спира batch-а', function () {
    $items = TeamNewsItem::factory()->count(3)->create(['title_bg' => null, 'classification' => null]);
    $badId = $items[1]->id;

    test()->mock(NewsClassifier::class, function ($m) use ($badId) {
        $m->shouldReceive('classify')
            ->andReturnUsing(function (TeamNewsItem $item) use ($badId) {
                if ($item->id === $badId) {
                    throw new LlmException('boom');
                }

                return classResult();
            });
        $m->shouldReceive('generateFullArticle')->twice()->andReturn(articleContent());
    });

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

it('сумира token usage от класификацията и статията', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(classResult(10, 20));
        $m->shouldReceive('generateFullArticle')->andReturn(articleContent(5, 7));
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['input_tokens'])->toBe(30)   // 2×10 класификация + 2×5 статия
        ->and($stats['output_tokens'])->toBe(54); // 2×20 + 2×7
});

it('не пипа items, които вече са обогатени', function () {
    $enriched = TeamNewsItem::factory()->create([
        'title_bg' => 'Вече преведено',
        'classification' => NewsClassification::Driver->value,
    ]);
    TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->once()->andReturn(classResult());
        $m->shouldReceive('generateFullArticle')->once()->andReturn(articleContent());
    });

    $stats = app(NewsEnricher::class)->enrichPending();

    expect($stats['processed'])->toBe(1) // само неенрихнатият
        ->and($enriched->fresh()->title_bg)->toBe('Вече преведено');
});

it('спазва --limit', function () {
    TeamNewsItem::factory()->count(5)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(classResult());
        $m->shouldReceive('generateFullArticle')->andReturn(articleContent());
    });

    $stats = app(NewsEnricher::class)->enrichPending(2);

    expect($stats['processed'])->toBe(2);
});

it('подава прогрес callback за всеки обработен елемент', function () {
    TeamNewsItem::factory()->count(2)->create(['title_bg' => null, 'classification' => null]);

    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(classResult());
        $m->shouldReceive('generateFullArticle')->andReturn(articleContent());
    });

    $progress = [];
    app(NewsEnricher::class)->enrichPending(50, function (TeamNewsItem $item, string $outcome, int $position, int $total, ?string $error) use (&$progress) {
        $progress[] = [$position, $total, $outcome, $error];
    });

    expect($progress)->toBe([[1, 2, 'published', null], [2, 2, 'published', null]]);
});

it('подава пълния текст на оригинала към генератора на статии', function () {
    TeamNewsItem::factory()->approved()->create([
        'full_article_bg' => null,
        'updated_at' => now()->subHour(), // отвъд прозореца за inline генерация
    ]);

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

it('прескача прясно публикуваните — те са в inline генерация от enrich', function () {
    // Публикувана току-що: паралелният news:enrich ѝ пише статията в момента.
    TeamNewsItem::factory()->approved()->create(['full_article_bg' => null]);

    test()->mock(NewsClassifier::class, fn ($m) => $m->shouldNotReceive('generateFullArticle'));

    $stats = app(NewsEnricher::class)->generateExtendedArticles();

    expect($stats['processed'])->toBe(0);
});
