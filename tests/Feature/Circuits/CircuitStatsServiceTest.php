<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Circuits\CircuitStatsService;

it('връща all-time класиране, групирано по driver_code през сезоните', function () {
    $s24 = Season::factory()->create(['year' => 2024]);
    $s26 = Season::factory()->current()->create(['year' => 2026]);

    // Hamilton: два записа (по сезон), но един driver_code → един ред в класирането.
    $ham24 = Driver::factory()->create(['season_id' => $s24->id, 'driver_code' => 'HAM', 'slug' => 'ham-24', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ham26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'HAM', 'slug' => 'ham-26', 'first_name' => 'Lewis', 'last_name' => 'Hamilton']);
    $ver26 = Driver::factory()->create(['season_id' => $s26->id, 'driver_code' => 'VER', 'slug' => 'ver-26']);

    // Pole за Hamilton в двата сезона → 2 pole позиции (групирани по driver_code).
    $monaco24 = Race::factory()->create(['season_id' => $s24->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham24->id]);
    $monaco26 = Race::factory()->create(['season_id' => $s26->id, 'jolpica_id' => 'monaco', 'pole_driver_id' => $ham26->id]);

    Result::factory()->position(1)->create(['race_id' => $monaco24->id, 'driver_id' => $ham24->id, 'points' => 25]);
    Result::factory()->position(2)->create(['race_id' => $monaco26->id, 'driver_id' => $ham26->id, 'points' => 18]);
    Result::factory()->position(1)->create(['race_id' => $monaco26->id, 'driver_id' => $ver26->id, 'points' => 25]);

    $standings = app(CircuitStatsService::class)->getAllTimeDriverStandings('monaco');

    // Hamilton е групиран в един ред (2 състезания), води по точки.
    expect($standings)->toHaveCount(2);

    $top = $standings->first();
    expect($top['code'])->toBe('HAM')
        ->and($top['name'])->toBe('Lewis Hamilton')
        ->and($top['points'])->toBe(43.0)  // 25 + 18 през двата сезона
        ->and($top['races'])->toBe(2)
        ->and($top['wins'])->toBe(1)
        ->and($top['poles'])->toBe(2)
        ->and($top['position'])->toBe(1);

    expect($standings->last()['code'])->toBe('VER')
        ->and($standings->last()['poles'])->toBe(0);
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
