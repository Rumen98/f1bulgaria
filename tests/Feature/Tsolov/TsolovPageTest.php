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

it('профилът съдържа рождена дата и серия', function () {
    expect(config('tsolov.birth_date'))->toBe('2007-09-06')
        ->and(config('tsolov.nationality'))->toBe('Българин')
        ->and(config('tsolov.milestones'))->toBeArray()
        ->and(count(config('tsolov.milestones')))->toBeGreaterThan(0);
});
