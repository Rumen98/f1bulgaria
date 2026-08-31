<?php

declare(strict_types=1);

it('404 предлага посоки, а не само връщане към началото', function () {
    $response = $this->get('/nyama-takava-stranica')->assertNotFound();

    $response->assertSee('Страницата не е намерена')
        ->assertSee('href="/news"', false)
        ->assertSee('href="/calendar"', false)
        ->assertSee('href="/standings"', false)
        ->assertSee('href="/drivers"', false)
        ->assertSee('href="/teams"', false)
        ->assertSee('href="/leaderboard"', false);
});

it('404 остава без външни асети, за да работи и при счупен build', function () {
    $this->get('/nyama-takava-stranica')
        ->assertNotFound()
        ->assertDontSee('/build/assets', false);
});

it('другите error страници нямат мрежата с линкове', function () {
    // 419 споделя шаблона, но е техническа грешка — там линковете нямат смисъл.
    $this->get('/nyama-takava-stranica')->assertSee('Полезни страници', false);

    $rendered = view('errors::419', ['exception' => new Exception])->render();

    expect($rendered)->not->toContain('Полезни страници');
});
