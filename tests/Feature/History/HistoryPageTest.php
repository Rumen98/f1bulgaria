<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('overview страницата /istoria връща 200', function () {
    $this->get('/istoria')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('History/Index'));
});

it('световната история /istoria/svetovna рендира ерите и легендите', function () {
    $expected = count(config('history-world-content.eras'));

    $this->get('/istoria/svetovna')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('History/World')
            ->has('hero.title')
            ->has('eras', $expected)
            ->where('eras.0.years', '1950–1960')
            ->has('legends'));
});

it('българската история /istoria/bulgaria рендира всички секции', function () {
    $expected = count(config('history-content.sections'));

    $this->get('/istoria/bulgaria')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('History/Bulgaria')
            ->has('hero.title')
            ->has('sections', $expected)
            ->where('sections.0.heading', 'Началото'));
});

it('легендите в световната история сочат само към съществуващи канонични пилоти', function () {
    // Без канонични записи → списъкът с легенди е празен (без счупени линкове).
    $this->get('/istoria/svetovna')
        ->assertInertia(fn (Assert $page) => $page->has('legends', 0));
});
