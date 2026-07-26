<?php

declare(strict_types=1);

use App\Models\TeamNewsItem;
use App\Services\News\SourceArticleFetcher;
use Illuminate\Support\Facades\Http;

it('извлича текста на статията от <article> и чисти шума', function () {
    $body = str_repeat('Verstappen led every lap of the race. ', 20);
    $html = '<html><body><nav>Site menu</nav>'
        ."<article><script>var tracker = 1;</script><h1>Race report</h1><p>{$body}</p></article>"
        .'<footer>Copyright</footer></body></html>';
    Http::fake(['example.com/*' => Http::response($html)]);

    $item = TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article']);

    $text = app(SourceArticleFetcher::class)->fetch($item);

    expect($text)->toContain('Verstappen led every lap')
        ->and($text)->not->toContain('var tracker')
        ->and($text)->not->toContain('Site menu');
});

it('връща null при блокиран източник', function () {
    Http::fake(['example.com/*' => Http::response('Forbidden', 403)]);

    $item = TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article']);

    expect(app(SourceArticleFetcher::class)->fetch($item))->toBeNull();
});

it('връща null при подозрително кратко съдържание (bot заглушка)', function () {
    Http::fake(['example.com/*' => Http::response('<html><body>Just a moment...</body></html>')]);

    $item = TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article']);

    expect(app(SourceArticleFetcher::class)->fetch($item))->toBeNull();
});

it('ограничава извлечения текст до 8000 знака', function () {
    $html = '<article><p>'.str_repeat('Full race classification data. ', 500).'</p></article>';
    Http::fake(['example.com/*' => Http::response($html)]);

    $item = TeamNewsItem::factory()->create(['external_url' => 'https://example.com/article']);

    expect(mb_strlen((string) app(SourceArticleFetcher::class)->fetch($item)))->toBeLessThanOrEqual(8000);
});
