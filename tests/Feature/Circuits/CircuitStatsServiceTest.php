<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Circuits\CircuitStatsService;
use App\Services\Drivers\CanonicalDriverBackfiller;

it('връща all-time класиране, групирано по canonical_id през сезоните', function () {
    $s24 = Season::factory()->create(['year' => 2024]);
    $s26 = Season::factory()->current()->create(['year' => 2026]);

    // Hamilton: два записа (по сезон), но един slug → един каноничен ред.
    $ham24 = Driver::factory()->create(['season_id' => $s24->id, 'driver_code' => 'HAM', 'slug' => 'lewis-hamilton', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ham26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'HAM', 'slug' => 'lewis-hamilton', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ver26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'VER', 'slug' => 'max-verstappen', 'first_name' => 'Max', 'last_name' => 'Verstappen']);

    $monaco24 = Race::factory()->create(['season_id' => $s24->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham24->id]);
    $monaco26 = Race::factory()->create(['season_id' => $s26->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham26->id]);

    Result::factory()->position(1)->create(['race_id' => $monaco24->id, 'driver_id' => $ham24->id, 'points' => 25]);
    Result::factory()->position(2)->create(['race_id' => $monaco26->id, 'driver_id' => $ham26->id, 'points' => 18]);
    Result::factory()->position(1)->create(['race_id' => $monaco26->id, 'driver_id' => $ver26->id, 'points' => 25]);

    app(CanonicalDriverBackfiller::class)->backfill();
    $standings = app(CircuitStatsService::class)->getAllTimeDriverStandings('monaco');

    // Hamilton и Verstappen имат по 1 победа; Hamilton води чрез pole tiebreak (2 vs 0).
    expect($standings)->toHaveCount(2);

    $top = $standings->first();
    expect($top['code'])->toBe('HAM')
        ->and($top['name'])->toBe('Lewis Hamilton')
        ->and($top['races'])->toBe(2)
        ->and($top['wins'])->toBe(1)
        ->and($top['poles'])->toBe(2)
        ->and($top['position'])->toBe(1)
        ->and($top)->not->toHaveKey('points'); // точките не се expose-ват вече

    expect($standings->last()['code'])->toBe('VER')
        ->and($standings->last()['poles'])->toBe(0);
});

it('подрежда по победи, не по точки (старите ери дават по-малко точки)', function () {
    $s = Season::factory()->current()->create();
    $winner = Driver::factory()->create(['season_id' => $s->id, 'driver_code' => 'SEN', 'slug' => 'ayrton-senna', 'first_name' => 'Ayrton', 'last_name' => 'Senna']);
    $pointsGuy = Driver::factory()->create(['season_id' => $s->id, 'driver_code' => 'XXX', 'slug' => 'points-guy', 'first_name' => 'Points', 'last_name' => 'Guy']);

    $r1 = Race::factory()->create(['season_id' => $s->id, 'jolpica_id' => 'monaco']);
    $r2 = Race::factory()->create(['season_id' => $s->id, 'jolpica_id' => 'monaco']);

    // Senna: 2 победи, но малко точки (стара ера).
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $winner->id, 'points' => 9]);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $winner->id, 'points' => 9]);
    // Другият: 0 победи, но повече точки.
    Result::factory()->position(2)->create(['race_id' => $r1->id, 'driver_id' => $pointsGuy->id, 'points' => 25]);
    Result::factory()->position(2)->create(['race_id' => $r2->id, 'driver_id' => $pointsGuy->id, 'points' => 25]);

    app(CanonicalDriverBackfiller::class)->backfill();
    $standings = app(CircuitStatsService::class)->getAllTimeDriverStandings('monaco');

    expect($standings->first()['code'])->toBe('SEN')   // 2 победи водят над 0
        ->and($standings->first()['wins'])->toBe(2)
        ->and($standings->last()['code'])->toBe('XXX');
});

it('връща пилота с най-много pole позиции на пистата', function () {
    $s24 = Season::factory()->create(['year' => 2024]);
    $s26 = Season::factory()->current()->create(['year' => 2026]);

    $ham24 = Driver::factory()->create(['season_id' => $s24->id, 'driver_code' => 'HAM', 'slug' => 'ham-24', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ham26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'HAM', 'slug' => 'ham-26', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ver26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'VER', 'slug' => 'ver-26']);

    // HAM с 2 pole (по сезон), VER с 1 → HAM води.
    Race::factory()->create(['season_id' => $s24->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham24->id]);
    Race::factory()->create(['season_id' => $s26->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham26->id]);
    Race::factory()->create(['season_id' => $s26->id, 'jolpica_id' => 'spa', 'pole_driver_id' => $ver26->id]);

    $most = app(CircuitStatsService::class)->getMostPolePosDriver('monaco');

    expect($most)->not->toBeNull()
        ->and($most['name'])->toBe('Lewis Hamilton')
        ->and($most['count'])->toBe(2);

    // Писта без pole данни → null.
    expect(app(CircuitStatsService::class)->getMostPolePosDriver('silverstone'))->toBeNull();
});

it('определя активна писта по последните 3 сезона', function () {
    $current = Season::factory()->current()->create(['year' => 2026]);
    $old = Season::factory()->create(['year' => 2000, 'is_current' => false]);

    Race::factory()->create(['season_id' => $current->id, 'jolpica_id' => 'bahrain']);
    Race::factory()->create(['season_id' => $old->id, 'jolpica_id' => 'estoril']);

    $service = app(CircuitStatsService::class);

    expect($service->isCircuitActive('bahrain'))->toBeTrue()
        ->and($service->isCircuitActive('estoril'))->toBeFalse();
});

it('връща рекорди и последни победители', function () {
    $season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $season->id, 'driver_code' => 'VER', 'first_name' => 'Max', 'last_name' => 'Verstappen']);
    $race = Race::factory()->create(['season_id' => $season->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $driver->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id, 'fastest_lap' => true]);

    $service = app(CircuitStatsService::class);

    expect($service->getRecords('monaco')['most_wins']['name'])->toBe('Max Verstappen')
        ->and($service->getRecords('monaco')['most_poles']['name'])->toBe('Max Verstappen')
        ->and($service->getLastWinners('monaco')->first()['driver'])->toBe('Max Verstappen');
});
