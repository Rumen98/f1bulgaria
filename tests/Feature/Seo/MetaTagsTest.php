<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Support\Seo;

/**
 * Тези тестове пазят най-скъпия SEO дефект: og таговете ТРЯБВА да са в
 * първоначалния HTML, защото Facebook/Viber/Telegram не изпълняват JavaScript.
 */
function publishedArticle(array $attributes = []): TeamNewsItem
{
    return TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Норис спечели квалификацията в Унгария',
        'summary_bg' => 'Ландо Норис записа пол позиция пред Люис Хамилтън на Хунгароринг.',
        'classification' => 'race',
        'published_at' => now()->subHours(2),
        ...$attributes,
    ]);
}

it('рендерира заглавието на статията в og:title сървърно', function () {
    $item = publishedArticle();

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect($html)->toContain('property="og:title" content="Норис спечели квалификацията в Унгария"')
        ->and($html)->toContain('property="og:type" content="article"')
        ->and($html)->toContain('Ландо Норис записа пол позиция');
});

it('няма дублирани og:title / description тагове', function () {
    $item = publishedArticle();

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect(substr_count($html, 'property="og:title"'))->toBe(1)
        ->and(substr_count($html, 'name="description"'))->toBe(1)
        ->and(substr_count($html, 'rel="canonical"'))->toBe(1);
});

it('подава ISO дати за article og таговете и NewsArticle schema', function () {
    $item = publishedArticle();

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect($html)->toContain('property="article:published_time"')
        ->and($html)->toContain('NewsArticle')
        ->and($html)->toContain('datePublished')
        ->and($html)->toContain($item->published_at->toIso8601String());
});

it('ползва снимка на пилот за og:image когато има такава', function () {
    $item = publishedArticle([
        'featured_image' => [
            'type' => 'driver_photo',
            'data' => ['photo_url' => 'https://example.com/norris.jpg', 'name' => 'Норис'],
        ],
    ]);

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect($html)->toContain('property="og:image" content="https://example.com/norris.jpg"');
});

it('статичните страници взимат описанието си сървърно от config/seo', function () {
    $html = $this->get('/calendar')->assertOk()->getContent();

    expect($html)->toContain('Календар на Формула 1')
        ->and($html)->toContain('часове в българско време');
});

it('canonical сочи каноничния хост от APP_URL, не хоста на заявката', function () {
    // Seo::resolvedCanonical() строи URL-а от config('app.url'), а не от
    // request()->url() — иначе www.padok.bg канонизира сам себе си.
    config()->set('app.url', 'https://padok.bg');

    $seo = app(Seo::class);

    $this->get('/kontakt')->assertOk();

    expect($seo->resolvedCanonical())->toBe('https://padok.bg/kontakt');
});

it('страницата на новина обявява canonical към себе си', function () {
    $item = publishedArticle();

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    expect($html)->toContain('rel="canonical" href="'.route('news.show', $item->slug).'"');
});

it('първоначалният HTML съдържа noscript навигация за не-JS ботове', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<noscript>')
        ->and($html)->toContain('href="'.route('news.index').'"');
});
