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

it('сваля пълния член до кратък след предлог', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Леклер изтри соцмедиите по време на трудният 2026 сезон',
        'summary_bg' => 'Говори се за новият регламент и за храмът на скоростта.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Леклер изтри соцмедиите по време на трудния 2026 сезон')
        ->and($item->summary_bg)->toBe('Говори се за новия регламент и за храма на скоростта.');
});

it('НЕ пипа пълния член в позиция на подлог', function () {
    // Обратната посока не се прави: там членът е падежният маркер и
    // сгрешена добавка би внесла нова грешка в публикуван текст.
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Храмът на скоростта може да бъде по-бавен',
        'summary_bg' => 'Новият технически регламент влиза в сила. Сезонът беше труден.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Храмът на скоростта може да бъде по-бавен')
        ->and($item->summary_bg)->toBe('Новият технически регламент влиза в сила. Сезонът беше труден.');
});

it('не чупи вече правилния кратък член, нито думи без член', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'По време на трудния сезон Верстапен спечели в Монца',
        'summary_bg' => 'Без болид, без гуми и с екип от петима души.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('По време на трудния сезон Верстапен спечели в Монца')
        ->and($item->summary_bg)->toBe('Без болид, без гуми и с екип от петима души.');
});

it('справя се със съставни прилагателни с „най-“', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Победа на най-бързият болид от сезона',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    expect($item->refresh()->title_bg)->toBe('Победа на най-бързия болид от сезона');
});

it('не пипа собствени имена след предлог', function () {
    // Целевата дума е само с малки букви — имената остават непокътнати.
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Разговор с Леклер и с Норис в Монца',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    expect($item->refresh()->title_bg)->toBe('Разговор с Леклер и с Норис в Монца');
});

it('поправя „Гран При“ на „Гран при“ — главна е само първата дума', function () {
    $item = TeamNewsItem::factory()->create([
        'title_bg' => 'Верстапен спечели Гран При на Италия',
        'summary_bg' => 'Следващото Гран При е в Сингапур.',
    ]);

    $this->artisan('news:normalize-bg')->assertSuccessful();

    $item->refresh();
    expect($item->title_bg)->toBe('Верстапен спечели Гран при на Италия')
        ->and($item->summary_bg)->toBe('Следващото Гран при е в Сингапур.');
});
