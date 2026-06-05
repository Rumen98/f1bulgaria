<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('страницата за история връща 200 и рендира всички секции', function () {
    $expected = count(config('history-content.sections'));

    $this->get('/istoria')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('History/Index')
            ->has('hero.title')
            ->has('hero.intro')
            ->has('sections', $expected));
});

it('всяка секция има heading и поне един параграф', function () {
    $this->get('/istoria')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.0.heading', 'Началото')
            ->has('sections.0.paragraphs', fn (Assert $p) => $p->where('0', fn ($v) => filled($v))->etc()));
});
