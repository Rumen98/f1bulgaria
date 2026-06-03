<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use Inertia\Testing\AssertableInertia as Assert;

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

    $this->get('/drivers/lewis-hamilton')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Drivers/Show')
            ->where('driver.name', 'Lewis Hamilton')
            ->has('seasonStats')
            ->has('allTimeStats')
            ->has('headToHead'));
});

it('връща 404 за несъществуващ пилот', function () {
    $this->get('/drivers/nonexistent')->assertNotFound();
});
