<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use Inertia\Testing\AssertableInertia as Assert;

function approvedNews(string $classification, int $importance = 3): TeamNewsItem
{
    return TeamNewsItem::factory()->create([
        'status' => NewsStatus::Approved->value,
        'title_bg' => 'Заглавие на български',
        'summary_bg' => 'Резюме.',
        'classification' => $classification,
        'importance_score' => $importance,
    ]);
}

it('показва само публикувани новини (не pending/rejected)', function () {
    approvedNews('race', 5);
    approvedNews('race', 2);
    approvedNews('driver', 2);
    TeamNewsItem::factory()->count(4)->create(['status' => NewsStatus::Pending->value]);
    TeamNewsItem::factory()->create(['status' => NewsStatus::Rejected->value]);

    // 3 одобрени → 1 featured + 2 в списъка.
    $this->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('News/Index')
            ->has('featured')
            ->has('items.data', 2));
});

it('страницира новините вместо да реже на твърд лимит', function () {
    // 26 публикувани: 1 featured + 24 на първа страница + 1 на втора.
    for ($i = 0; $i < 26; $i++) {
        approvedNews('race', 2);
    }

    $this->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('featured')
            ->has('items.data', 24));

    $this->get('/news?page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
});

it('не повтаря featured новината в решетката', function () {
    $top = approvedNews('race', 5);
    approvedNews('race', 1);

    $this->get('/news')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('featured.slug', $top->slug)
            ->has('items.data', 1)
            ->where('items.data.0.slug', fn (string $slug) => $slug !== $top->slug));
});

it('филтрира по категория', function () {
    approvedNews('race');
    approvedNews('race');
    approvedNews('driver');

    // cat=driver → само 1 (без featured при активен филтър).
    $this->get('/news?cat=driver')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeCat', 'driver')
            ->where('featured', null)
            ->has('items.data', 1));
});

it('групира анализите (technical/rumor/business)', function () {
    approvedNews('technical');
    approvedNews('rumor');
    approvedNews('race');

    $this->get('/news?cat=analysis')
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 2)); // technical + rumor
});

it('началната страница показва само одобрени топ новини', function () {
    approvedNews('race');
    approvedNews('driver');
    TeamNewsItem::factory()->count(3)->create(['status' => NewsStatus::Pending->value]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('topNews', 2));
});

it('показва собствена article страница за одобрена новина', function () {
    $item = approvedNews('race', 4);
    approvedNews('race', 2); // свързана новина

    $this->get("/news/{$item->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('News/Show')
            ->where('article.title', 'Заглавие на български')
            ->where('article.slug', $item->slug)
            ->has('related'));
});

it('връща 404 за несъществуващ slug', function () {
    $this->get('/news/nyama-takava-novina')->assertNotFound();
});

it('връща 404 за непубликувана (pending) новина', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::Pending->value,
        'title_bg' => 'Чакаща новина',
    ]);

    expect($item->slug)->not->toBeNull();
    $this->get("/news/{$item->slug}")->assertNotFound();
});

it('article страницата сочи към оригиналния източник', function () {
    $source = TeamNewsSource::factory()->create(['name' => 'Autosport']);
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::Approved->value,
        'title_bg' => 'Новина за източника',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'source_id' => $source->id,
        'external_url' => 'https://autosport.com/article-123',
    ]);

    $this->get("/news/{$item->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('article.external_url', 'https://autosport.com/article-123')
            ->where('article.source', 'Autosport'));
});

it('генерира уникален slug при еднакви заглавия', function () {
    $a = approvedNews('race');
    $b = approvedNews('race');

    expect($a->slug)->not->toBe($b->slug);
});

it('article страницата показва пълната статия когато е генерирана', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::Approved->value,
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Кратко резюме.',
        'full_article_bg' => "Дълъг първи параграф.\n\nВтори параграф.",
        'our_analysis_bg' => 'Нашият анализ.',
        'key_facts' => ['Факт едно', 'Факт две'],
        'classification' => 'race',
    ]);

    $this->get("/news/{$item->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('News/Show')
            ->where('article.full_article', "Дълъг първи параграф.\n\nВтори параграф.")
            ->where('article.analysis', 'Нашият анализ.')
            ->has('article.key_facts', 2));
});

it('article страницата пада към резюме когато няма пълна статия', function () {
    $item = approvedNews('race');

    $this->get("/news/{$item->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('article.full_article', null)
            ->where('article.summary', 'Резюме.'));
});

it('article страницата подава featured_image към компонента', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::Approved->value,
        'title_bg' => 'Заглавие',
        'summary_bg' => 'Резюме.',
        'classification' => 'race',
        'featured_image' => ['type' => 'generic', 'data' => ['color' => '#e10600', 'label' => 'Състезание']],
    ]);

    $this->get("/news/{$item->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('article.image.type', 'generic'));
});
