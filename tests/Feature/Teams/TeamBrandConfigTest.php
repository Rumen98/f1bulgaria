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

it('конфигът ползва реалните slug-ове на текущите отбори с валидни форми', function () {
    $brands = config('team-brands');
    $validShapes = ['shield', 'hexagon', 'angular', 'classic', 'circle'];

    // Ключовете трябва да съвпадат с реалните slug-ове в базата (както са от Jolpica).
    expect($brands)->toHaveKeys([
        'ferrari', 'mercedes', 'red-bull', 'mclaren', 'williams',
        'aston-martin', 'alpine-f1-team', 'haas-f1-team', 'rb-f1-team', 'audi', 'cadillac-f1-team',
    ]);

    foreach ($brands as $slug => $cfg) {
        expect($cfg['shape'])->toBeIn($validShapes, "shape за {$slug}")
            ->and($cfg['name_bg'])->toBeString()
            ->and($cfg['colors'])->toBeArray()
            ->and(count($cfg['colors']))->toBe(2);
    }
});

it('изписва правилните български имена на отборите (Алпин, не Алпайн)', function () {
    expect(config('team-brands.alpine-f1-team.name_bg'))->toBe('Алпин')
        ->and(config('team-brands.ferrari.name_bg'))->toBe('Ферари')
        ->and(config('team-brands.red-bull.name_bg'))->toBe('Ред Бул')
        ->and(config('team-brands.aston-martin.name_bg'))->toBe('Астън Мартин');
});
