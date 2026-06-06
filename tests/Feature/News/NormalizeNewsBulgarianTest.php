<?php

declare(strict_types=1);

use App\Models\TeamNewsItem;

it('поправя Алпайн → Алпин в заглавие и резюме', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Мерцедес преговаря за дял в Алпайн',
        'summary_bg' => 'Слухове около Алпайн и бъдещето.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Мерцедес преговаря за дял в Алпин')
        ->and($item->summary_bg)->toBe('Слухове около Алпин и бъдещето.');
});

it('не пипа коректните новини', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => 'Ферари и Макларън в битка', 'summary_bg' => 'Чисто.']);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    expect($item->refresh()->title_bg)->toBe('Ферари и Макларън в битка');
});
