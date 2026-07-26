<?php

declare(strict_types=1);

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;

it('връща валиден RSS с публикуваните новини', function () {
    $item = TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Заглавие на новина',
        'summary_bg' => 'Кратко резюме.',
        'published_at' => now()->subHour(),
    ]);

    $response = $this->get('/feed')->assertOk();

    $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    $xml = $response->getContent();

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('<rss version="2.0"')
        ->and($xml)->toContain('Заглавие на новина')
        ->and($xml)->toContain(route('news.show', $item->slug))
        ->and($xml)->toContain('<pubDate>');
});

it('не показва непубликувани новини във фийда', function () {
    TeamNewsItem::factory()->create([
        'status' => NewsStatus::Pending->value,
        'title_bg' => 'Чакаща новина',
        'published_at' => now(),
    ]);

    expect($this->get('/feed')->getContent())->not->toContain('Чакаща новина');
});

it('екранира специални знаци в заглавията', function () {
    TeamNewsItem::factory()->create([
        'status' => NewsStatus::AutoPublished->value,
        'title_bg' => 'Ферари & Макларън <в битка>',
        'published_at' => now(),
    ]);

    $xml = $this->get('/feed')->assertOk()->getContent();

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('&amp;')
        ->and($xml)->not->toContain('<в битка>');
});

it('фийдът е обявен в head-а на сайта', function () {
    expect($this->get('/')->getContent())
        ->toContain('type="application/rss+xml"');
});
