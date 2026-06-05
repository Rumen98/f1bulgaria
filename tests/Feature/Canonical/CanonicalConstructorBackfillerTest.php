<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\ConstructorCanonical;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Teams\CanonicalConstructorBackfiller;

function backfillCanonicalConstructors(): array
{
    return app(CanonicalConstructorBackfiller::class)->backfill();
}

it('създава 1 каноничен запис на отбор и свързва per-season записите', function () {
    $s1 = Season::factory()->create(['year' => 2023, 'is_current' => false]);
    $s2 = Season::factory()->current()->create(['year' => 2024]);
    $c1 = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'Ferrari', 'slug' => 'ferrari', 'color_hex' => '#dc0000']);
    $c2 = Constructor::factory()->create(['season_id' => $s2->id, 'name' => 'Ferrari', 'slug' => 'ferrari', 'color_hex' => '#dc0000']);
    $d1 = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $c1->id]);
    $d2 = Driver::factory()->create(['season_id' => $s2->id, 'constructor_id' => $c2->id]);

    $r1 = Race::factory()->create(['season_id' => $s1->id]);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $d1->id, 'grid_position' => 1]); // win + pole
    $r2 = Race::factory()->create(['season_id' => $s2->id]);
    Result::factory()->position(2)->create(['race_id' => $r2->id, 'driver_id' => $d2->id]); // podium

    backfillCanonicalConstructors();

    expect(ConstructorCanonical::count())->toBe(1);

    $c = ConstructorCanonical::first();
    expect($c->slug)->toBe('ferrari')
        ->and($c->total_wins)->toBe(1)
        ->and($c->total_podiums)->toBe(2) // P1 + P2
        ->and($c->total_poles)->toBe(1)
        ->and($c->total_races)->toBe(2)
        ->and($c->is_active)->toBeTrue()
        ->and($c1->fresh()->canonical_id)->toBe($c->id)
        ->and($c2->fresh()->canonical_id)->toBe($c->id);
});

it('сумира резултатите на двамата пилоти на отбора', function () {
    $s = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $s->id, 'slug' => 'mclaren', 'name' => 'McLaren']);
    $a = Driver::factory()->create(['season_id' => $s->id, 'constructor_id' => $team->id]);
    $b = Driver::factory()->create(['season_id' => $s->id, 'constructor_id' => $team->id]);

    $race = Race::factory()->create(['season_id' => $s->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $a->id, 'grid_position' => 1]);
    Result::factory()->position(3)->create(['race_id' => $race->id, 'driver_id' => $b->id]);

    backfillCanonicalConstructors();

    $c = ConstructorCanonical::where('slug', 'mclaren')->first();
    expect($c->total_wins)->toBe(1)
        ->and($c->total_podiums)->toBe(2) // P1 + P3, едно състезание, двама пилоти
        ->and($c->total_races)->toBe(1);  // едно състезание, не се брои два пъти
});

it('различни отбори получават различни канонични записи', function () {
    $s = Season::factory()->current()->create();
    Constructor::factory()->create(['season_id' => $s->id, 'slug' => 'ferrari', 'name' => 'Ferrari']);
    Constructor::factory()->create(['season_id' => $s->id, 'slug' => 'mercedes', 'name' => 'Mercedes']);

    backfillCanonicalConstructors();

    expect(ConstructorCanonical::count())->toBe(2)
        ->and(Constructor::whereNull('canonical_id')->count())->toBe(0);
});

it('е идемпотентна — втори run не дублира', function () {
    $s = Season::factory()->current()->create();
    Constructor::factory()->create(['season_id' => $s->id, 'slug' => 'williams', 'name' => 'Williams']);

    backfillCanonicalConstructors();
    backfillCanonicalConstructors();

    expect(ConstructorCanonical::count())->toBe(1);
});
