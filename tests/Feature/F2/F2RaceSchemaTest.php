<?php

declare(strict_types=1);

use App\Enums\F2SessionType;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use Illuminate\Support\Facades\Schema;

it('създава таблиците за F2 състезателни данни', function () {
    expect(Schema::hasTable('f2_races'))->toBeTrue()
        ->and(Schema::hasTable('f2_race_sessions'))->toBeTrue()
        ->and(Schema::hasTable('f2_results'))->toBeTrue()
        ->and(Schema::hasColumn('f2_drivers', 'car_number'))->toBeTrue();
});

it('изгражда веригата race → session → result', function () {
    $season = F2Season::create(['year' => 2026, 'is_current' => true]);
    $driver = F2Driver::create([
        'f2_season_id' => $season->id, 'first_name' => 'Nikola', 'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov', 'car_number' => 6, 'country_code' => 'BGR',
    ]);

    $race = F2Race::create([
        'f2_season_id' => $season->id, 'circuit_jolpica_id' => 'miami',
        'location_name' => 'Miami', 'round' => 3, 'slug' => '2026-miami',
    ]);

    $session = F2RaceSession::create([
        'f2_race_id' => $race->id, 'session_type' => F2SessionType::SprintRace,
        'laps' => 23, 'pole_position_driver_id' => $driver->id,
    ]);

    $result = F2Result::create([
        'f2_race_session_id' => $session->id, 'f2_driver_id' => $driver->id,
        'position' => 1, 'grid_position' => 2, 'laps_completed' => 23,
        'time_or_gap' => '39:09.726', 'points' => 10, 'status' => 'Finished', 'fastest_lap' => true,
    ]);

    expect($race->sessions)->toHaveCount(1)
        ->and($session->session_type)->toBe(F2SessionType::SprintRace)
        ->and($session->session_type->label())->toBe('Спринт')
        ->and($session->race->is($race))->toBeTrue()
        ->and($session->poleDriver->is($driver))->toBeTrue()
        ->and($result->driver->is($driver))->toBeTrue()
        ->and($result->fastest_lap)->toBeTrue()
        ->and($driver->results)->toHaveCount(1);
});

it('каскадно трие резултатите при триене на сесия', function () {
    $season = F2Season::create(['year' => 2025]);
    $driver = F2Driver::create(['f2_season_id' => $season->id, 'first_name' => 'A', 'last_name' => 'B', 'slug' => 'a-b']);
    $race = F2Race::create(['f2_season_id' => $season->id, 'location_name' => 'X', 'round' => 1, 'slug' => '2025-x']);
    $session = F2RaceSession::create(['f2_race_id' => $race->id, 'session_type' => F2SessionType::FeatureRace]);
    F2Result::create(['f2_race_session_id' => $session->id, 'f2_driver_id' => $driver->id, 'position' => 1, 'points' => 25]);

    $session->delete();

    expect(F2Result::count())->toBe(0);
});
