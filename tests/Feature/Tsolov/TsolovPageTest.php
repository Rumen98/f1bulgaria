<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('страницата на Цолов връща 200 и рендира профила', function () {
    $this->get('/tsolov')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tsolov')
            ->where('profile.name', 'Никола Цолов')
            ->has('profile.bio_bg')
            ->has('profile.milestones'));
});

it('профилът съдържа рождена дата, серия, титли и разширена биография', function () {
    // Официален FIA F2 профил: роден 21.12.2006 (не 2007!).
    expect(config('tsolov.birth_date'))->toBe('2006-12-21')
        ->and(config('tsolov.nationality'))->toBe('Българин')
        ->and(config('tsolov.milestones'))->toBeArray()
        ->and(count(config('tsolov.milestones')))->toBeGreaterThan(0)
        ->and(config('tsolov.titles'))->toBeArray()
        ->and(count(config('tsolov.titles')))->toBeGreaterThanOrEqual(2)
        // Разширена биография (брой думи; str_word_count не брои кирилица).
        ->and(count(preg_split('/\s+/', trim(implode(' ', config('tsolov.bio_bg'))))))->toBeGreaterThan(150);
});

it('не съдържа опровергани твърдения (фактчек 24.07.2026)', function () {
    $flat = json_encode(config('tsolov'), JSON_UNESCAPED_UNICODE);

    // Цолов никога не е печелил Eurocup-3 (шампион 2023 е Esteban Masson).
    expect($flat)->not->toContain('Eurocup')
        // 2026 е първият му пълен сезон във Ф2 (новак), не „втори сезон".
        ->and($flat)->not->toContain('Втори сезон във Формула 2')
        // Дебютът във FIA Формула 3 е 2023 с ART, не 2024.
        ->and(collect(config('tsolov.milestones'))->firstWhere('year', 2023)['event'])->toContain('Формула 3');
});

it('мини класирането има бележка за актуалност (standings_note)', function () {
    $this->get('/tsolov')
        ->assertInertia(fn (Assert $page) => $page
            ->has('profile.standings_note')
            ->has('profile.standings', 5));
});

it('страницата рендира титлите', function () {
    $this->get('/tsolov')
        ->assertInertia(fn (Assert $page) => $page->has('profile.titles', 2));
});
