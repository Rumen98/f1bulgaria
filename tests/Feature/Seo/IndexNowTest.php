<?php

declare(strict_types=1);

use App\Enums\NewsClassification;
use App\Models\TeamNewsItem;
use App\Services\News\IndexNowPinger;
use App\Services\News\Llm\NewsArticleContent;
use App\Services\News\Llm\NewsClassificationResult;
use App\Services\News\Llm\NewsClassifier;
use App\Services\News\NewsEnricher;
use App\Services\News\SourceArticleFetcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('news.enrich_sleep_ms', 0);
    config()->set('services.indexnow.key', 'test-key-1234567890abcdef');
    config()->set('app.url', 'https://padok.bg');
});

it('не праща заявка без конфигуриран ключ', function () {
    config()->set('services.indexnow.key', null);
    Http::fake();

    expect(app(IndexNowPinger::class)->ping(['https://padok.bg/news/test']))->toBeFalse();

    Http::assertNothingSent();
});

it('не праща заявка при празен списък', function () {
    Http::fake();

    expect(app(IndexNowPinger::class)->ping([]))->toBeFalse();

    Http::assertNothingSent();
});

it('праща хоста, ключа и URL-ите', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

    expect(app(IndexNowPinger::class)->ping(['https://padok.bg/news/test']))->toBeTrue();

    Http::assertSent(fn ($request) => $request['host'] === 'padok.bg'
        && $request['key'] === 'test-key-1234567890abcdef'
        && $request['urlList'] === ['https://padok.bg/news/test']);
});

it('провал на IndexNow не хвърля изключение', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('nope', 500)]);

    expect(app(IndexNowPinger::class)->ping(['https://padok.bg/news/test']))->toBeFalse();
});

it('enrich уведомява IndexNow за публикуваните новини', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => null, 'classification' => null]);

    test()->mock(SourceArticleFetcher::class, fn ($m) => $m->shouldReceive('fetch')->andReturn(null));
    test()->mock(NewsClassifier::class, function ($m) {
        $m->shouldReceive('classify')->andReturn(new NewsClassificationResult(
            titleBg: 'Заглавие',
            summaryBg: 'Резюме.',
            classification: NewsClassification::Race,
            constructorId: null,
            importanceScore: 3,
            rawResponse: '{}',
            tokenUsage: ['input_tokens' => 1, 'output_tokens' => 1],
        ));
        $m->shouldReceive('generateFullArticle')->andReturn(new NewsArticleContent(
            fullArticleBg: 'Статия.',
            keyFacts: [],
            analysisBg: 'Анализ.',
            tokenUsage: ['input_tokens' => 1, 'output_tokens' => 1],
        ));
    });

    $pinger = test()->mock(IndexNowPinger::class);
    $pinger->shouldReceive('ping')
        ->once()
        ->withArgs(fn (array $urls) => $urls === [route('news.show', $item->fresh()->slug)]);

    app(NewsEnricher::class)->enrichPending();
});
