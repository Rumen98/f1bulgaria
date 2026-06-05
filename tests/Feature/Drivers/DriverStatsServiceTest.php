<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\DriverCanonical;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\CanonicalDriverBackfiller;
use App\Services\Drivers\DriverStatsService;

function service(): DriverStatsService
{
    return app(DriverStatsService::class);
}

it('изчислява сезонна статистика на пилот', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $a = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race = Race::factory()->create(['season_id' => $season->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $a->id, 'points' => 25, 'fastest_lap' => true, 'grid_position' => 1]);

    $stats = service()->getSeasonStats($a, $season);

    expect($stats['points'])->toBe(25.0)
        ->and($stats['wins'])->toBe(1)
        ->and($stats['podiums'])->toBe(1)
        ->and($stats['poles'])->toBe(1)
        ->and($stats['fastest_laps'])->toBe(1)
        ->and($stats['position'])->toBe(1);
});

it('сумира all-time статистика по driver_code през сезоните', function () {
    $s2024 = Season::factory()->create(['year' => 2024, 'is_current' => false]);
    $s2026 = Season::factory()->current()->create(['year' => 2026]);

    // Един и същ пилот (HAM) с различни записи по сезони.
    $ham2024 = Driver::factory()->create(['season_id' => $s2024->id, 'driver_code' => 'HAM', 'slug' => 'hamilton-2024']);
    $ham2026 = Driver::factory()->create(['season_id' => $s2026->id, 'driver_code' => 'HAM', 'slug' => 'hamilton']);

    // Pole (старт от P1) в двата сезона → all-time poles = 2.
    $r24 = Race::factory()->create(['season_id' => $s2024->id]);
    Result::factory()->position(1)->create(['race_id' => $r24->id, 'driver_id' => $ham2024->id, 'points' => 25, 'grid_position' => 1]);
    $r26 = Race::factory()->create(['season_id' => $s2026->id]);
    Result::factory()->position(2)->create(['race_id' => $r26->id, 'driver_id' => $ham2026->id, 'points' => 18, 'grid_position' => 1]);

    $stats = service()->getAllTimeStats($ham2026);

    expect($stats['points'])->toBe(43.0)   // 25 + 18 (двата сезона)
        ->and($stats['wins'])->toBe(1)
        ->and($stats['podiums'])->toBe(2)
        ->and($stats['poles'])->toBe(2)
        ->and($stats['races'])->toBe(2)
        ->and($stats['seasons'])->toBe(2);
});

it('изчислява кариерни постижения с win rate', function () {
    $season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $season->id, 'driver_code' => 'VER']);

    $r1 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $driver->id, 'fastest_lap' => true, 'grid_position' => 1]);
    $r2 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'spa']);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $driver->id, 'grid_position' => 5]);
    $r3 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'monza']);
    Result::factory()->position(3)->create(['race_id' => $r3->id, 'driver_id' => $driver->id, 'grid_position' => 8]);

    $a = service()->getAchievements($driver);

    expect($a['wins'])->toBe(2)
        ->and($a['podiums'])->toBe(3)       // P1, P1, P3
        ->and($a['poles'])->toBe(1)
        ->and($a['fastest_laps'])->toBe(1)
        ->and($a['races'])->toBe(3)
        ->and($a['win_rate'])->toBe(66.7);  // 2/3
});

it('групира победите по писта, подредени по брой', function () {
    $season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $season->id, 'driver_code' => 'VER']);

    // 2 победи в Монако, 1 в Спа, без победа в Силвърстоун.
    foreach (['monaco', 'monaco', 'spa'] as $i => $slug) {
        $race = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => $slug, 'round' => $i + 1]);
        Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id]);
    }
    $loser = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'silverstone', 'round' => 9]);
    Result::factory()->position(5)->create(['race_id' => $loser->id, 'driver_id' => $driver->id]);

    $wins = service()->getCircuitWins($driver);

    expect($wins)->toHaveCount(2)
        ->and($wins->first()['circuit_slug'])->toBe('monaco')
        ->and($wins->first()['wins'])->toBe(2)
        ->and($wins->last()['circuit_slug'])->toBe('spa')
        ->and($wins->last()['wins'])->toBe(1);
});

it('изчислява head-to-head срещу съотборника (по позиция и стартова позиция)', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $a = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);
    $b = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race = Race::factory()->create(['season_id' => $season->id]);
    // A финишира пред B (P1 vs P2), но стартира зад него (grid 2 vs 1).
    Result::factory()->create(['race_id' => $race->id, 'driver_id' => $a->id, 'position' => 1, 'grid_position' => 2]);
    Result::factory()->create(['race_id' => $race->id, 'driver_id' => $b->id, 'position' => 2, 'grid_position' => 1]);

    $h2h = service()->getHeadToHeadVsTeammate($a, $season);

    expect($h2h['teammate'])->toBe($b->fullName())
        ->and($h2h['race_wins'])->toBe(1)
        ->and($h2h['race_losses'])->toBe(0)
        ->and($h2h['quali_wins'])->toBe(0)
        ->and($h2h['quali_losses'])->toBe(1);
});

it('изчислява all-time статистика за каноничен пилот (по canonical_id)', function () {
    $s2024 = Season::factory()->create(['year' => 2024, 'is_current' => false]);
    $s2026 = Season::factory()->current()->create(['year' => 2026]);
    $t1 = Constructor::factory()->create(['season_id' => $s2024->id, 'name' => 'Mercedes', 'color_hex' => '#00d2be', 'slug' => 'mercedes']);
    $t2 = Constructor::factory()->create(['season_id' => $s2026->id, 'name' => 'Ferrari', 'color_hex' => '#dc0000', 'slug' => 'ferrari']);
    $d24 = Driver::factory()->create(['season_id' => $s2024->id, 'constructor_id' => $t1->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    $d26 = Driver::factory()->create(['season_id' => $s2026->id, 'constructor_id' => $t2->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);

    $r24 = Race::factory()->create(['season_id' => $s2024->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(1)->create(['race_id' => $r24->id, 'driver_id' => $d24->id, 'points' => 25, 'grid_position' => 1, 'fastest_lap' => true]);
    $r26 = Race::factory()->create(['season_id' => $s2026->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(2)->create(['race_id' => $r26->id, 'driver_id' => $d26->id, 'points' => 18, 'grid_position' => 1]);

    app(CanonicalDriverBackfiller::class)->backfill();
    $canonical = DriverCanonical::where('slug', 'lewis-hamilton')->first();

    $stats = service()->getStatsForCanonical($canonical);

    expect($stats['wins'])->toBe(1)
        ->and($stats['podiums'])->toBe(2)
        ->and($stats['poles'])->toBe(2)
        ->and($stats['races'])->toBe(2)
        ->and($stats['fastest_laps'])->toBe(1)
        ->and($stats['points'])->toBe(43.0)
        ->and($stats['seasons'])->toBe(2)
        ->and($stats['win_rate'])->toBe(50.0); // 1/2
});

it('изгражда кариерна хронология и победи по писта за каноничен пилот', function () {
    $s1 = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    $s2 = Season::factory()->create(['year' => 1991, 'is_current' => false]);
    $t1 = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'McLaren', 'color_hex' => '#ff8000', 'slug' => 'mclaren']);
    $t2 = Constructor::factory()->create(['season_id' => $s2->id, 'name' => 'McLaren', 'color_hex' => '#ff8000', 'slug' => 'mclaren']);
    $d1 = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $t1->id, 'slug' => 'ayrton-senna', 'driver_code' => 'SEN']);
    $d2 = Driver::factory()->create(['season_id' => $s2->id, 'constructor_id' => $t2->id, 'slug' => 'ayrton-senna', 'driver_code' => 'SEN']);

    $r1 = Race::factory()->create(['season_id' => $s1->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $d1->id, 'points' => 9]);
    $r2 = Race::factory()->create(['season_id' => $s2->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $d2->id, 'points' => 9]);

    app(CanonicalDriverBackfiller::class)->backfill();
    $canonical = DriverCanonical::where('slug', 'ayrton-senna')->first();

    $timeline = service()->getCareerTimelineForCanonical($canonical);
    $circuitWins = service()->getCircuitWinsForCanonical($canonical);

    expect($timeline)->toHaveCount(2)
        ->and($timeline->first())->toMatchArray(['year' => 1990, 'team' => 'McLaren', 'wins' => 1, 'podiums' => 1])
        ->and($circuitWins)->toHaveCount(1)
        ->and($circuitWins->first())->toMatchArray(['circuit_slug' => 'monaco', 'wins' => 2]);
});
