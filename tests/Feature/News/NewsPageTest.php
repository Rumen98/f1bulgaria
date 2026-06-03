<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
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
            ->has('items', 2));
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
            ->has('items', 1));
});

it('групира анализите (technical/rumor/business)', function () {
    approvedNews('technical');
    approvedNews('rumor');
    approvedNews('race');

    $this->get('/news?cat=analysis')
        ->assertInertia(fn (Assert $page) => $page->has('items', 2)); // technical + rumor
});

it('началната страница показва само одобрени топ новини', function () {
    approvedNews('race');
    approvedNews('driver');
    TeamNewsItem::factory()->count(3)->create(['status' => NewsStatus::Pending->value]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('topNews', 2));
});
