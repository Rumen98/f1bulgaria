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

it('нулира SEO състоянието между заявките (scoped singleton не изтича)', function () {
    $item = publishedArticle();

    // Статия → og:type=article, canonical към статията, NewsArticle schema.
    $this->get("/news/{$item->slug}")->assertOk();

    // Следваща заявка в СЪЩИЯ контейнер не бива да наследи нищо от нея.
    $html = $this->get('/calendar')->assertOk()->getContent();

    expect($html)->toContain('property="og:type" content="website"')
        ->and($html)->not->toContain($item->slug)
        ->and($html)->not->toContain('NewsArticle')
        ->and($html)->not->toContain('article:published_time');
});

it('заглавието не носи inertia атрибут — клиентът не бива да го трие', function () {
    $html = $this->get('/calendar')->assertOk()->getContent();

    expect($html)->toContain('<title>Календар на Формула 1 — Падок</title>')
        ->and($html)->not->toContain('<title inertia>');
});

it('подава заглавието и като prop за SPA навигация', function () {
    $this->get('/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('seoTitle', 'Календар на Формула 1 — Падок'));
});

it('не обявява размери за външна снимка на пилот', function () {
    $item = publishedArticle([
        'featured_image' => [
            'type' => 'driver_photo',
            'data' => ['photo_url' => 'https://example.com/portrait.jpg', 'name' => 'Норис'],
        ],
    ]);

    $html = $this->get("/news/{$item->slug}")->assertOk()->getContent();

    // Портретна снимка + обявени 1200x630 = отрязана глава в социалната карта.
    expect($html)->not->toContain('og:image:width')
        ->and($html)->toContain('name="twitter:card" content="summary"');
});

it('обявява размери за собствения банер', function () {
    $html = $this->get('/calendar')->assertOk()->getContent();

    expect($html)->toContain('property="og:image:width" content="1200"')
        ->and($html)->toContain('name="twitter:card" content="summary_large_image"');
});

it('непозната категория се канонизира към /news, не към себе си', function () {
    $html = $this->get('/news?cat=junk-value')->assertOk()->getContent();

    // Canonical и og:url сочат чистия /news — иначе всеки произволен ?cat=
    // ражда self-canonical дубликат и яде crawl бюджета.
    expect($html)->toContain('rel="canonical" href="'.route('news.index').'"')
        ->and($html)->toContain('property="og:url" content="'.route('news.index').'"')
        ->and($html)->not->toContain('cat=junk-value"'); // не и в мета таг
});

it('третира непознатата категория като „Всички“', function () {
    $this->get('/news?cat=junk-value')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activeCat', 'all'));
});
