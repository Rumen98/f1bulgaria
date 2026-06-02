<?php

declare(strict_types=1);

use App\Models\TeamNewsSource;
use App\Services\News\NewsFetchException;
use App\Services\News\ParsedFeedItem;
use App\Services\News\RssFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

const FEED_URL = 'https://feed.test/f1';

function feedSource(): TeamNewsSource
{
    return TeamNewsSource::factory()->create(['feed_url' => FEED_URL]);
}

it('парсва RSS 2.0 с всички полета', function () {
    Http::fake([FEED_URL => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    $items = app(RssFetcher::class)->fetch(feedSource());

    expect($items)->toHaveCount(3)
        ->and($items->first())->toBeInstanceOf(ParsedFeedItem::class);

    $first = $items->first();
    expect($first->externalUrl)->toBe('https://www.autosport.com/f1/news/verstappen-wins-sao-paulo/')
        ->and($first->externalGuid)->toBe('autosport-f1-100001')
        ->and($first->titleOriginal)->toBe('Verstappen wins chaotic São Paulo Grand Prix from 17th')
        ->and($first->contentSnippet)->toContain('São Paulo Grand Prix')
        ->and($first->contentSnippet)->not->toContain('<strong>') // HTML е изчистен
        ->and($first->publishedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('парсва Atom формат', function () {
    Http::fake([FEED_URL => Http::response(newsFixture('atom_sample.xml'), 200)]);

    $items = app(RssFetcher::class)->fetch(feedSource());

    expect($items)->toHaveCount(2)
        ->and($items->first()->externalUrl)->toBe('https://the-race.com/formula-1/verstappen-interlagos-rain/')
        ->and($items->first()->externalGuid)->toBe('the-race-f1-200001')
        ->and($items->first()->contentSnippet)->toContain('racecraft');
});

it('ограничава content_snippet до 500 знака', function () {
    $long = '<p>'.str_repeat('Lorem ipsum dolor sit amet. ', 60).'</p>';
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><link>https://t.test</link><description>d</description>'
        .'<item><title>Long</title><link>https://t.test/long</link><description><![CDATA['.$long.']]></description><guid>g-long</guid><pubDate>Sun, 03 Nov 2024 18:30:00 +0000</pubDate></item>'
        .'</channel></rss>';
    Http::fake([FEED_URL => Http::response($rss, 200)]);

    $item = app(RssFetcher::class)->fetch(feedSource())->first();

    expect(mb_strlen($item->contentSnippet))->toBeLessThanOrEqual(500);
});

it('повтаря при 500 и успява на втория опит', function () {
    Http::fake([
        FEED_URL => Http::sequence()
            ->push('', 500)
            ->push(newsFixture('autosport_sample.xml'), 200),
    ]);

    $items = app(RssFetcher::class)->fetch(feedSource());

    expect($items)->toHaveCount(3);
    Http::assertSentCount(2);
});

it('хвърля NewsFetchException след 3 неуспешни опита (5xx)', function () {
    Http::fake([FEED_URL => Http::response('', 500)]);

    expect(fn () => app(RssFetcher::class)->fetch(feedSource()))
        ->toThrow(NewsFetchException::class);

    Http::assertSentCount(3);
});

it('хвърля NewsFetchException при невалиден XML', function () {
    Http::fake([FEED_URL => Http::response('това не е xml', 200)]);

    expect(fn () => app(RssFetcher::class)->fetch(feedSource()))
        ->toThrow(NewsFetchException::class);
});
