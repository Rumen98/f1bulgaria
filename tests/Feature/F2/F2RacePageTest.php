<?php

declare(strict_types=1);

use App\Enums\F2SessionType;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\F2Team;
use Inertia\Testing\AssertableInertia as Assert;

function seedF2Race(): F2Race
{
    $season = F2Season::create(['year' => 2026, 'is_current' => true]);
    $campos = F2Team::create(['f2_season_id' => $season->id, 'name' => 'Campos Racing', 'slug' => 'campos-racing']);
    $tsolov = F2Driver::create([
        'f2_season_id' => $season->id, 'f2_team_id' => $campos->id, 'first_name' => 'Nikola', 'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov', 'car_number' => 6, 'country_code' => 'BUL',
    ]);
    $race = F2Race::create([
        'f2_season_id' => $season->id, 'circuit_jolpica_id' => 'albert_park',
        'location_name' => 'Melbourne', 'round' => 1, 'slug' => '2026-melbourne',
    ]);
    $feature = F2RaceSession::create([
        'f2_race_id' => $race->id, 'session_type' => F2SessionType::FeatureRace,
        'fastest_lap_driver_id' => $tsolov->id, 'fastest_lap_time' => '1:31.730',
    ]);
    F2Result::create([
        'f2_race_session_id' => $feature->id, 'f2_driver_id' => $tsolov->id, 'position' => 1,
        'grid_position' => 2, 'points' => 25, 'status' => 'Finished', 'fastest_lap' => true,
    ]);
    F2RaceSession::create(['f2_race_id' => $race->id, 'session_type' => F2SessionType::SprintRace]);

    return $race;
}

it('/f2/races/{race}/feature показва резултатите с Цолов', function () {
    seedF2Race();

    $this->get('/f2/races/2026-melbourne/feature')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('F2/RaceShow')
            ->where('race.location', 'Melbourne')
            ->where('sessionType', 'feature')
            ->where('sessionLabel', 'Главно състезание')
            ->where('hasSprint', true)
            ->where('hasFeature', true)
            ->where('results.0.driver', 'Nikola Tsolov')
            ->where('results.0.position', 1)
            ->where('results.0.is_bulgarian', true)
            ->where('fastestLap.driver', 'Nikola Tsolov'));
});

it('връща 404 при несъществуваща сесия', function () {
    $season = F2Season::create(['year' => 2026]);
    F2Race::create(['f2_season_id' => $season->id, 'location_name' => 'X', 'round' => 1, 'slug' => '2026-x']);

    // race съществува, но без feature сесия
    $this->get('/f2/races/2026-x/feature')->assertNotFound();
});

it('връща 404 при несъществуващ race', function () {
    $this->get('/f2/races/nope/sprint')->assertNotFound();
});
