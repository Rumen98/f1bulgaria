<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Season;
use App\Services\Teams\CanonicalConstructorBackfiller;
use Inertia\Testing\AssertableInertia as Assert;

it('споделя team-brands конфигурацията към фронтенда', function () {
    $season = Season::factory()->current()->create();
    Constructor::factory()->create(['season_id' => $season->id, 'slug' => 'ferrari', 'name' => 'Ferrari']);
    app(CanonicalConstructorBackfiller::class)->backfill();

    $this->get('/teams')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('teamBrands.ferrari', fn (Assert $brand) => $brand
                ->where('shape', 'shield')
                ->where('font', 'italic')
                ->etc())
            ->has('teamBrands.mercedes'));
});

it('конфигът съдържа всичките 10 текущи отбора с валидни форми', function () {
    $brands = config('team-brands');
    $validShapes = ['shield', 'hexagon', 'angular', 'classic', 'circle'];

    expect($brands)->toHaveKeys(['ferrari', 'mercedes', 'red_bull', 'mclaren', 'williams', 'aston_martin', 'alpine', 'haas', 'rb', 'kick_sauber']);

    foreach ($brands as $slug => $cfg) {
        expect($cfg['shape'])->toBeIn($validShapes, "shape за {$slug}")
            ->and($cfg['colors'])->toBeArray()
            ->and(count($cfg['colors']))->toBe(2);
    }
});
