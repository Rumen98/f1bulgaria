<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;

it('сумира точките от главното състезание и спринта в класирането на пилотите', function () {
    $season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $season->id]);
    $race = Race::factory()->create(['season_id' => $season->id, 'has_sprint' => true]);

    // Печели и главното (25), и спринта (8) → 33 точки, но 1 победа (само главното).
    Result::factory()->position(1)->create([
        'race_id' => $race->id, 'driver_id' => $driver->id, 'points' => 25,
    ]);
    Result::factory()->position(1)->sprint()->create([
        'race_id' => $race->id, 'driver_id' => $driver->id, 'points' => 8,
    ]);

    $row = app(StandingsService::class)->drivers($season)->first();

    expect($row['points'])->toBe(33.0)
        ->and($row['wins'])->toBe(1);
});

it('сумира спринт точките и в класирането на конструкторите', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $driver = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);
    $race = Race::factory()->create(['season_id' => $season->id, 'has_sprint' => true]);

    Result::factory()->position(2)->create([
        'race_id' => $race->id, 'driver_id' => $driver->id, 'points' => 18,
    ]);
    Result::factory()->position(3)->sprint()->create([
        'race_id' => $race->id, 'driver_id' => $driver->id, 'points' => 6,
    ]);

    $row = app(StandingsService::class)->constructors($season)->first();

    expect($row['points'])->toBe(24.0)
        ->and($row['wins'])->toBe(0);
});

it('позволява един пилот да има и race, и sprint ред за същото състезание', function () {
    $season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $season->id]);
    $race = Race::factory()->create(['season_id' => $season->id, 'has_sprint' => true]);

    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id]);
    Result::factory()->position(1)->sprint()->create(['race_id' => $race->id, 'driver_id' => $driver->id]);

    expect(Result::where('race_id', $race->id)->where('driver_id', $driver->id)->count())->toBe(2);
});
