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

it('поправя преведена фамилия: Лекар → Леклер', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Ръсел блокира Лекар в Монца',
        'summary_bg' => 'Лекар остана зад Ръсел.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Ръсел блокира Леклер в Монца')
        ->and($item->summary_bg)->toBe('Леклер остана зад Ръсел.');
});

it('НЕ пипа „Лекарят" — границата на думата пази истинското съществително', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Лекарят на пистата прегледа пилота',
        'summary_bg' => 'Лекарите от медицинския център потвърдиха, че Лекаря е бил на място.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Лекарят на пистата прегледа пилота')
        ->and($item->summary_bg)->toBe('Лекарите от медицинския център потвърдиха, че Лекаря е бил на място.');
});

it('нормализира и тялото на статията, и анализа', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Чисто заглавие',
        'full_article_bg' => 'Лекар финишира трети, а Хамилтон беше пети.',
        'our_analysis_bg' => 'Алпайн губи темпо в Зандвоорт.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->full_article_bg)->toBe('Леклер финишира трети, а Хамилтън беше пети.')
        ->and($item->our_analysis_bg)->toBe('Алпин губи темпо в Зандворт.');
});

it('нормализира ключовите факти, без да чупи JSON масива', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Чисто заглавие',
        'key_facts' => ['Лекар стартира втори', 'Хамилтон отпадна в 12-а обиколка'],
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    expect($item->refresh()->key_facts)->toBe([
        'Леклер стартира втори',
        'Хамилтън отпадна в 12-а обиколка',
    ]);
});

it('е идемпотентна — второто пускане не променя нищо', function () {
    $item = TeamNewsItem::factory()->create(['title_bg' => 'Лекар и Хамилтон в Зандвоорт']);

    $this->artisan('news:normalize-bg')->assertSuccessful();
    $afterFirst = $item->refresh()->title_bg;

    $this->artisan('news:normalize-bg')->assertSuccessful();

    expect($item->refresh()->title_bg)->toBe($afterFirst)
        ->and($afterFirst)->toBe('Леклер и Хамилтън в Зандворт');
});
