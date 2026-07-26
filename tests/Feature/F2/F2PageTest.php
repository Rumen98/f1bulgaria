<?php

declare(strict_types=1);

use Database\Seeders\F2SeedSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(F2SeedSeeder::class)->run();
});

it('/f2 показва текущия сезон, класиране и шампиони', function () {
    $this->get('/f2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('F2/Index')
            ->where('season', 2025)
            ->has('standings')
            ->has('champions', 8)
            ->has('seasons'));
});

it('/f2/seasons/{year} показва конкретен сезон с шампиона му', function () {
    $this->get('/f2/seasons/2017')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season', 2017)
            // Име за екрана през DriverName::display — кирилица за slug-овете
            // от config/driver-names-bg.php („Льоклер", не буквалното „Леклерк").
            ->where('standings.0.name', 'Шарл Льоклер')
            ->where('standings.0.is_champion', true));
});

it('/f2/seasons/{year} за несъществуващ сезон връща 404', function () {
    $this->get('/f2/seasons/1999')->assertNotFound();
});
