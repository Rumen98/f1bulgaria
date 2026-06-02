<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\Constructor;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use App\Services\News\NewsAggregationService;
use Illuminate\Support\Facades\Http;

it('записва нови елементи със статус pending', function () {
    $source = TeamNewsSource::factory()->create(['feed_url' => 'https://a.test/rss']);
    Http::fake(['https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    $stats = app(NewsAggregationService::class)->aggregateOne($source);

    expect($stats)->toMatchArray(['fetched' => 3, 'new' => 3, 'duplicates' => 0, 'errors' => null])
        ->and(TeamNewsItem::count())->toBe(3)
        ->and(TeamNewsItem::where('status', NewsStatus::Pending->value)->count())->toBe(3)
        ->and(TeamNewsItem::first()->source_id)->toBe($source->id);
});

it('наследява constructor_id от отборен източник', function () {
    $constructor = Constructor::factory()->create();
    $source = TeamNewsSource::factory()->forConstructor($constructor)->create(['feed_url' => 'https://a.test/rss']);
    Http::fake(['https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    app(NewsAggregationService::class)->aggregateOne($source);

    expect(TeamNewsItem::where('constructor_id', $constructor->id)->count())->toBe(3);
});

it('дедупликира по external_url при повторен fetch', function () {
    $source = TeamNewsSource::factory()->create(['feed_url' => 'https://a.test/rss']);
    Http::fake(['https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    $service = app(NewsAggregationService::class);
    $service->aggregateOne($source);
    $second = $service->aggregateOne($source);

    expect($second)->toMatchArray(['fetched' => 3, 'new' => 0, 'duplicates' => 3])
        ->and(TeamNewsItem::count())->toBe(3);
});

it('дедупликира по external_guid дори при различен URL', function () {
    $source = TeamNewsSource::factory()->create(['feed_url' => 'https://a.test/rss']);

    // Вече съществуващ елемент със същия guid, но друг URL.
    TeamNewsItem::factory()->create([
        'source_id' => $source->id,
        'external_url' => 'https://old.test/original',
        'external_guid' => 'shared-guid-1',
    ]);

    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><link>https://a.test</link><description>d</description>'
        .'<item><title>Same story, new URL</title><link>https://a.test/reposted</link><description>x</description>'
        .'<guid isPermaLink="false">shared-guid-1</guid><pubDate>Sun, 03 Nov 2024 18:30:00 +0000</pubDate></item>'
        .'</channel></rss>';
    Http::fake(['https://a.test/rss' => Http::response($rss, 200)]);

    $stats = app(NewsAggregationService::class)->aggregateOne($source);

    expect($stats)->toMatchArray(['fetched' => 1, 'new' => 0, 'duplicates' => 1])
        ->and(TeamNewsItem::count())->toBe(1);
});

it('обновява last_fetched_at', function () {
    $source = TeamNewsSource::factory()->create(['feed_url' => 'https://a.test/rss', 'last_fetched_at' => null]);
    Http::fake(['https://a.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200)]);

    app(NewsAggregationService::class)->aggregateOne($source);

    expect($source->fresh()->last_fetched_at)->not->toBeNull();
});

it('грешка в един източник не блокира друг', function () {
    $bad = TeamNewsSource::factory()->create(['name' => 'Bad', 'feed_url' => 'https://bad.test/rss']);
    $good = TeamNewsSource::factory()->create(['name' => 'Good', 'feed_url' => 'https://good.test/rss']);

    Http::fake([
        'https://bad.test/rss' => Http::response('', 500),
        'https://good.test/rss' => Http::response(newsFixture('autosport_sample.xml'), 200),
    ]);

    $stats = collect(app(NewsAggregationService::class)->aggregateAll())->keyBy('source');

    expect($stats['Bad']['errors'])->not->toBeNull()
        ->and($stats['Bad']['new'])->toBe(0)
        ->and($stats['Good']['new'])->toBe(3)
        ->and(TeamNewsItem::count())->toBe(3);
});
