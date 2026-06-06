<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\ConstructorCanonical;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Teams\CanonicalConstructorBackfiller;
use App\Services\Teams\TeamStatsService;

it('изчислява сезонната статистика на отбора', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $a = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);
    $b = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race1 = Race::factory()->create(['season_id' => $season->id, 'round' => 1]);
    Result::factory()->position(1)->create(['race_id' => $race1->id, 'driver_id' => $a->id, 'points' => 25, 'fastest_lap' => true, 'grid_position' => 1]);
    Result::factory()->position(3)->create(['race_id' => $race1->id, 'driver_id' => $b->id, 'points' => 15, 'grid_position' => 4]);

    $race2 = Race::factory()->create(['season_id' => $season->id, 'round' => 2]);
    Result::factory()->dnf()->create(['race_id' => $race2->id, 'driver_id' => $a->id, 'grid_position' => 8]);
    Result::factory()->position(2)->create(['race_id' => $race2->id, 'driver_id' => $b->id, 'points' => 18, 'grid_position' => 3]);

    $stats = app(TeamStatsService::class)->getSeasonStats($team, $season);

    expect($stats->points)->toBe(58.0)   // 25 + 15 + 18
        ->and($stats->wins)->toBe(1)
        ->and($stats->podiums)->toBe(3)   // P1, P3, P2
        ->and($stats->poles)->toBe(1)
        ->and($stats->fastestLaps)->toBe(1)
        ->and($stats->dnfs)->toBe(1)
        ->and($stats->position)->toBe(1); // единствен отбор
});

it('изчислява all-time статистика за каноничен конструктор', function () {
    $s1 = Season::factory()->create(['year' => 2023, 'is_current' => false]);
    $s2 = Season::factory()->current()->create(['year' => 2024]);
    $c1 = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'Ferrari', 'slug' => 'ferrari']);
    $c2 = Constructor::factory()->create(['season_id' => $s2->id, 'name' => 'Ferrari', 'slug' => 'ferrari']);
    $d1 = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $c1->id]);
    $d2 = Driver::factory()->create(['season_id' => $s2->id, 'constructor_id' => $c2->id]);

    $r1 = Race::factory()->create(['season_id' => $s1->id]);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $d1->id, 'points' => 25, 'grid_position' => 1, 'fastest_lap' => true]);
    $r2 = Race::factory()->create(['season_id' => $s2->id]);
    Result::factory()->dnf()->create(['race_id' => $r2->id, 'driver_id' => $d2->id, 'points' => 0, 'grid_position' => 5]);

    app(CanonicalConstructorBackfiller::class)->backfill();
    $canonical = ConstructorCanonical::where('slug', 'ferrari')->first();

    $canonical->update(['championships_count' => 16]);
    $stats = app(TeamStatsService::class)->getStatsForCanonical($canonical);

    expect($stats['wins'])->toBe(1)
        ->and($stats['poles'])->toBe(1)
        ->and($stats['races'])->toBe(2)
        ->and($stats['fastest_laps'])->toBe(1)
        ->and($stats['dnfs'])->toBe(1)
        ->and($stats)->not->toHaveKey('points') // all-time точки премахнати (подвеждащи)
        ->and($stats['championships'])->toBe(16)
        ->and($stats['seasons'])->toBe(2)
        ->and($stats['position'])->toBeNull()
        ->and($stats['win_rate'])->toBe(50.0);
});

it('връща индекс на каноничните отбори с активни/легенди', function () {
    $current = Season::factory()->current()->create(['year' => 2024]);
    $old = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    Constructor::factory()->create(['season_id' => $current->id, 'name' => 'Ferrari', 'slug' => 'ferrari']);
    $lotus = Constructor::factory()->create(['season_id' => $old->id, 'name' => 'Team Lotus', 'slug' => 'lotus']);

    // Lotus има поне едно състезание, за да мине филтъра „races>0".
    $driver = Driver::factory()->create(['season_id' => $old->id, 'constructor_id' => $lotus->id]);
    $race = Race::factory()->create(['season_id' => $old->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id]);

    app(CanonicalConstructorBackfiller::class)->backfill();

    $index = app(TeamStatsService::class)->getTeamIndex();

    expect($index)->toHaveCount(2)
        ->and($index->firstWhere('slug', 'ferrari')['is_active'])->toBeTrue()
        ->and($index->firstWhere('slug', 'lotus')['is_active'])->toBeFalse()
        ->and($index->firstWhere('slug', 'lotus')['seasons'])->toBe(1);
});

it('скрива отбори без състезания (освен ако са активни)', function () {
    $current = Season::factory()->current()->create(['year' => 2024]);
    $old = Season::factory()->create(['year' => 1960, 'is_current' => false]);
    // Активен отбор без състезания (нов за сезона) → показва се.
    Constructor::factory()->create(['season_id' => $current->id, 'name' => 'Cadillac', 'slug' => 'cadillac']);
    // Обскурен исторически без състезания → скрива се.
    Constructor::factory()->create(['season_id' => $old->id, 'name' => 'Politoys', 'slug' => 'politoys']);

    app(CanonicalConstructorBackfiller::class)->backfill();

    $index = app(TeamStatsService::class)->getTeamIndex();

    expect($index->pluck('slug'))->toContain('cadillac')
        ->and($index->pluck('slug'))->not->toContain('politoys');
});
