<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Standings\StandingsService;

it('изчислява класирането на пилотите от резултатите', function () {
    $season = Season::factory()->current()->create();
    $alpha = Driver::factory()->create(['season_id' => $season->id]);
    $beta = Driver::factory()->create(['season_id' => $season->id]);

    $race1 = Race::factory()->create(['season_id' => $season->id, 'round' => 1]);
    $race2 = Race::factory()->create(['season_id' => $season->id, 'round' => 2]);

    // Alpha печели и двете (25 + 25), Beta е втори двукратно (18 + 18).
    Result::factory()->position(1)->create(['race_id' => $race1->id, 'driver_id' => $alpha->id, 'points' => 25]);
    Result::factory()->position(2)->create(['race_id' => $race1->id, 'driver_id' => $beta->id, 'points' => 18]);
    Result::factory()->position(1)->create(['race_id' => $race2->id, 'driver_id' => $alpha->id, 'points' => 25]);
    Result::factory()->position(2)->create(['race_id' => $race2->id, 'driver_id' => $beta->id, 'points' => 18]);

    $standings = app(StandingsService::class)->drivers($season);

    expect($standings->first()['driver']->id)->toBe($alpha->id)
        ->and($standings->first()['points'])->toBe(50.0)
        ->and($standings->first()['wins'])->toBe(2)
        ->and($standings->last()['points'])->toBe(36.0);
});

it('агрегира точките на конструкторите от двамата пилоти', function () {
    $season = Season::factory()->current()->create();
    $team = Constructor::factory()->create(['season_id' => $season->id]);
    $d1 = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);
    $d2 = Driver::factory()->create(['season_id' => $season->id, 'constructor_id' => $team->id]);

    $race = Race::factory()->create(['season_id' => $season->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $d1->id, 'points' => 25]);
    Result::factory()->position(3)->create(['race_id' => $race->id, 'driver_id' => $d2->id, 'points' => 15]);

    $standings = app(StandingsService::class)->constructors($season);

    expect($standings->first()['constructor']->id)->toBe($team->id)
        ->and($standings->first()['points'])->toBe(40.0);
});
