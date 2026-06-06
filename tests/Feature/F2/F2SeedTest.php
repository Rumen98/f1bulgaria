<?php

declare(strict_types=1);

use App\Models\F2Driver;
use App\Models\F2Season;
use Database\Seeders\F2SeedSeeder;

beforeEach(function () {
    app(F2SeedSeeder::class)->run();
});

it('създава сезони, отбори и шампиони', function () {
    expect(F2Season::count())->toBeGreaterThanOrEqual(9)
        ->and(F2Driver::where('is_champion', true)->count())->toBe(8)
        ->and(F2Season::current()->year)->toBe(2025);
});

it('Льоклер е шампион за 2017 в Prema', function () {
    $leclerc = F2Driver::query()->where('slug', 'charles-leclerc')->first();

    expect($leclerc)->not->toBeNull()
        ->and($leclerc->is_champion)->toBeTrue()
        ->and($leclerc->position)->toBe(1)
        ->and($leclerc->season->year)->toBe(2017)
        ->and($leclerc->team->name)->toBe('Prema Racing');
});

it('Цолов има F2 запис за текущия сезон', function () {
    $tsolov = F2Driver::query()->where('slug', 'nikola-tsolov')->first();

    expect($tsolov)->not->toBeNull()
        ->and($tsolov->fullName())->toBe('Nikola Tsolov')
        ->and($tsolov->season->is_current)->toBeTrue();
});

it('сийдърът е идемпотентен', function () {
    app(F2SeedSeeder::class)->run();
    app(F2SeedSeeder::class)->run();

    expect(F2Season::count())->toBe(9)
        ->and(F2Driver::where('slug', 'charles-leclerc')->count())->toBe(1);
});
