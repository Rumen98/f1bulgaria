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

function seedTsolovF2(): void
{
    $season = F2Season::create(['year' => 2026, 'is_current' => true]);
    $team = F2Team::create(['f2_season_id' => $season->id, 'name' => 'Campos Racing', 'slug' => 'campos-racing']);
    $tsolov = F2Driver::create([
        'f2_season_id' => $season->id, 'f2_team_id' => $team->id, 'first_name' => 'Nikola', 'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov', 'car_number' => 6, 'country_code' => 'BUL', 'points' => 75, 'position' => 3,
    ]);
    $race = F2Race::create(['f2_season_id' => $season->id, 'location_name' => 'Melbourne', 'round' => 1, 'slug' => '2026-melbourne']);
    $feature = F2RaceSession::create(['f2_race_id' => $race->id, 'session_type' => F2SessionType::FeatureRace]);
    F2Result::create(['f2_race_session_id' => $feature->id, 'f2_driver_id' => $tsolov->id, 'position' => 1, 'points' => 25]);
}

it('/tsolov показва текущите F2 статистики', function () {
    seedTsolovF2();

    $this->get('/tsolov')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tsolov')
            ->where('f2.season', 2026)
            ->where('f2.team', 'Campos Racing')
            ->where('f2.position', 3)
            ->where('f2.wins', 1)
            ->where('f2.latest.location', 'Melbourne'));
});

it('/tsolov без F2 данни → f2 е null', function () {
    $this->get('/tsolov')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('f2', null));
});

it('/f2 показва spotlight за българския състезател', function () {
    seedTsolovF2();

    $this->get('/f2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bulgarianSpotlight.name', 'Nikola Tsolov')
            ->where('bulgarianSpotlight.team', 'Campos Racing'));
});
