<?php

declare(strict_types=1);

use App\Enums\F2SessionType;
use App\Models\Driver;
use App\Models\F2Driver;
use App\Models\F2Race;
use App\Models\F2RaceSession;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\CanonicalDriverBackfiller;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // F1 circuit (за да съществува /circuits/albert_park)
    $s = Season::factory()->current()->create();
    $d = Driver::factory()->create(['season_id' => $s->id, 'slug' => 'x-y', 'first_name' => 'X', 'last_name' => 'Y']);
    $race = Race::factory()->create(['season_id' => $s->id, 'jolpica_id' => 'albert_park', 'circuit' => 'Albert Park']);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $d->id, 'grid_position' => 1]);
    app(CanonicalDriverBackfiller::class)->backfill();
});

it('показва F2 секция на пистата с победителите', function () {
    $f2season = F2Season::create(['year' => 2026, 'is_current' => true]);
    $tsolov = F2Driver::create([
        'f2_season_id' => $f2season->id, 'first_name' => 'Nikola', 'last_name' => 'Tsolov',
        'slug' => 'nikola-tsolov', 'country_code' => 'BUL',
    ]);
    $f2race = F2Race::create(['f2_season_id' => $f2season->id, 'circuit_jolpica_id' => 'albert_park', 'location_name' => 'Melbourne', 'round' => 1, 'slug' => '2026-melbourne']);
    $feature = F2RaceSession::create(['f2_race_id' => $f2race->id, 'session_type' => F2SessionType::FeatureRace]);
    F2Result::create(['f2_race_session_id' => $feature->id, 'f2_driver_id' => $tsolov->id, 'position' => 1, 'points' => 25]);

    $this->get('/circuits/albert_park')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('f2Winners', 1)
            // Име за екрана през DriverName::display — кирилица за slug-овете
            // от config/driver-names-bg.php.
            ->where('f2Winners.0.driver', 'Никола Цолов')
            ->where('f2Winners.0.year', 2026));
});

it('няма F2 секция за писта без F2 състезания', function () {
    $this->get('/circuits/albert_park')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('f2Winners', 0));
});
