<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\DriverStatsService;

function service(): DriverStatsService
{
    return app(DriverStatsService::class);
}

it('изчислява сезонна статистика на пилот', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $a = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race = Race::factory()->create(['season_id' => $season->id, 'pole_driver_id' => $a->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $a->id, 'points' => 25, 'fastest_lap' => true]);

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

    // Pole в двата сезона → all-time poles = 2 (по driver_code, различни Driver записи).
    $r24 = Race::factory()->create(['season_id' => $s2024->id, 'pole_driver_id' => $ham2024->id]);
    Result::factory()->position(1)->create(['race_id' => $r24->id, 'driver_id' => $ham2024->id, 'points' => 25]);
    $r26 = Race::factory()->create(['season_id' => $s2026->id, 'pole_driver_id' => $ham2026->id]);
    Result::factory()->position(2)->create(['race_id' => $r26->id, 'driver_id' => $ham2026->id, 'points' => 18]);

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

    $r1 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $driver->id]);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $driver->id, 'fastest_lap' => true]);
    $r2 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'spa']);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $driver->id]);
    $r3 = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'monza']);
    Result::factory()->position(3)->create(['race_id' => $r3->id, 'driver_id' => $driver->id]);

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
