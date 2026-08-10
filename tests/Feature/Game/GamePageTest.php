<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.game' => true]);
    Cache::forget('game.tracks.index');
});

afterEach(function () {
    Cache::forget('game.tracks.index');
});

it('връща 404 когато флагът е изключен', function () {
    config(['features.game' => false]);

    $this->get('/game')->assertNotFound();
});

it('показва каталога на пистите', function () {
    $this->get('/game')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Game/Index')
            ->has('tracks')
        );
});

it('подава само метаданни, не самите точки на трасето', function () {
    // Точките са стотици килобайта на писта — ако някога влязат в Inertia
    // отговора, всяко зареждане на страницата ще влачи всички писти наведнъж.
    $this->get('/game')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tracks.0', fn (Assert $track) => $track
                ->has('slug')
                ->has('name')
                ->has('location')
                ->has('length')
                ->has('elevation')
                ->missing('points')
                ->missing('landmarks')
            )
        );
});

it('не гърми когато каталогът липсва', function () {
    // Пресен clone преди `php artisan game:generate-tracks` — страницата
    // трябва да се зареди празна, не да хвърли.
    $index = public_path('game/tracks/index.json');
    $backup = file_exists($index) ? file_get_contents($index) : null;

    if ($backup !== null) {
        unlink($index);
    }

    try {
        $this->get('/game')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tracks', 0));
    } finally {
        if ($backup !== null) {
            file_put_contents($index, $backup);
        }
    }
});
