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
    expect(config('tsolov.birth_date'))->toBe('2007-09-06')
        ->and(config('tsolov.nationality'))->toBe('Българин')
        ->and(config('tsolov.milestones'))->toBeArray()
        ->and(count(config('tsolov.milestones')))->toBeGreaterThan(0)
        ->and(config('tsolov.titles'))->toBeArray()
        ->and(count(config('tsolov.titles')))->toBeGreaterThanOrEqual(2)
        // Разширена биография (брой думи; str_word_count не брои кирилица).
        ->and(count(preg_split('/\s+/', trim(implode(' ', config('tsolov.bio_bg'))))))->toBeGreaterThan(150);
});

it('страницата рендира титлите', function () {
    $this->get('/tsolov')
        ->assertInertia(fn (Assert $page) => $page->has('profile.titles', 2));
});
