<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Drivers\CanonicalDriverBackfiller;
use App\Services\Drivers\DriverStatsService;
use Inertia\Testing\AssertableInertia as Assert;

function backfillDriverCanonical(): void
{
    app(CanonicalDriverBackfiller::class)->backfill();
}

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $this->team = Constructor::factory()->create(['season_id' => $this->season->id]);
});

it('показва списъка с пилоти', function () {
    Driver::factory()->count(4)->create(['season_id' => $this->season->id, 'constructor_id' => $this->team->id]);

    $this->get('/drivers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Drivers/Index')->has('drivers', 4));
});

it('показва детайлната страница на пилот', function () {
    $driver = Driver::factory()->create([
        'season_id' => $this->season->id,
        'constructor_id' => $this->team->id,
        'first_name' => 'Lewis',
        'last_name' => 'Hamilton',
        'slug' => 'lewis-hamilton',
    ]);
    backfillDriverCanonical();

    $this->get('/drivers/lewis-hamilton')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Drivers/Show')
            // Кирилицата води — това е името в <h1> и <title> (SEO).
            ->where('driver.name', 'Люис Хамилтън')
            ->where('driver.name_latin', 'Lewis Hamilton')
            ->has('seasonStats')
            ->has('allTimeStats')
            ->has('achievements')
            ->has('achievements.win_rate')
            ->has('circuitWins')
            ->has('careerTimeline')
            ->where('isHistorical', false)
            ->has('headToHead'));
});

it('връща 404 за несъществуващ пилот', function () {
    $this->get('/drivers/nonexistent')->assertNotFound();
});

it('резолва исторически пилот извън текущия сезон', function () {
    $old = Season::factory()->create(['year' => 1994, 'is_current' => false]);
    $team = Constructor::factory()->create(['season_id' => $old->id, 'name' => 'Williams']);
    $senna = Driver::factory()->create([
        'season_id' => $old->id, 'constructor_id' => $team->id,
        'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna', 'driver_code' => 'SEN',
    ]);
    $race = Race::factory()->create(['season_id' => $old->id, 'jolpica_id' => 'monaco']);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $senna->id, 'points' => 10]);
    backfillDriverCanonical();

    $this->get('/drivers/ayrton-senna')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Drivers/Show')
            ->where('driver.name', 'Айртон Сена')
            ->where('isHistorical', true)
            ->where('season', 1994)
            ->has('careerTimeline', 1));
});

it('предпочита текущия сезон при cross-season резолюция', function () {
    $old = Season::factory()->create(['year' => 2024, 'is_current' => false]);
    Driver::factory()->create(['season_id' => $old->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    Driver::factory()->create(['season_id' => $this->season->id, 'constructor_id' => $this->team->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    backfillDriverCanonical();

    $this->get('/drivers/lewis-hamilton')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('isHistorical', false));
});

it('?season избира конкретен сезон на пилота', function () {
    $old = Season::factory()->create(['year' => 2014, 'is_current' => false]);
    $merc = Constructor::factory()->create(['season_id' => $old->id, 'name' => 'Mercedes', 'slug' => 'mercedes']);
    Driver::factory()->create(['season_id' => $old->id, 'constructor_id' => $merc->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    Driver::factory()->create(['season_id' => $this->season->id, 'constructor_id' => $this->team->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton', 'driver_code' => 'HAM']);
    backfillDriverCanonical();

    // Без параметър → последният сезон.
    $this->get('/drivers/lewis-hamilton')
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedSeason', $this->season->year)
            ->where('seasons', [$this->season->year, 2014]));

    // С ?season=2014 → избира 2014 (отбор Mercedes).
    $this->get('/drivers/lewis-hamilton?season=2014')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedSeason', 2014)
            ->where('season', 2014)
            ->where('driver.team', 'Mercedes'));

    // Невалиден сезон → fallback към последния (без 404).
    $this->get('/drivers/lewis-hamilton?season=1850')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedSeason', $this->season->year));
});

it('getCareerTimeline връща структурирани данни по сезони', function () {
    $s1 = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    $s2 = Season::factory()->create(['year' => 1991, 'is_current' => false]);
    $t1 = Constructor::factory()->create(['season_id' => $s1->id, 'name' => 'McLaren', 'color_hex' => '#ff8000']);
    $t2 = Constructor::factory()->create(['season_id' => $s2->id, 'name' => 'McLaren', 'color_hex' => '#ff8000']);
    $d1 = Driver::factory()->create(['season_id' => $s1->id, 'constructor_id' => $t1->id, 'driver_code' => 'SEN', 'slug' => 'sen-90']);
    $d2 = Driver::factory()->create(['season_id' => $s2->id, 'constructor_id' => $t2->id, 'driver_code' => 'SEN', 'slug' => 'sen-91']);
    $r1 = Race::factory()->create(['season_id' => $s1->id]);
    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $d1->id, 'points' => 9]);
    $r2 = Race::factory()->create(['season_id' => $s2->id]);
    Result::factory()->position(2)->create(['race_id' => $r2->id, 'driver_id' => $d2->id, 'points' => 6]);

    $timeline = app(DriverStatsService::class)->getCareerTimeline($d2);

    expect($timeline)->toHaveCount(2)
        ->and($timeline->first())->toMatchArray(['year' => 1990, 'team' => 'McLaren', 'wins' => 1, 'points' => 9.0])
        ->and($timeline->last())->toMatchArray(['year' => 1991, 'wins' => 0, 'points' => 6.0]);
});
