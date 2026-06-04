<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
    $driver = Driver::factory()->create(['season_id' => $this->season->id, 'driver_code' => 'VER']);
    $race = Race::factory()->create(['season_id' => $this->season->id, 'jolpica_id' => 'monaco', 'circuit' => 'Circuit de Monaco']);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id]);
});

it('показва списъка с писти', function () {
    $this->get('/circuits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Circuits/Index')->has('circuits', 1));
});

it('подрежда активните писти преди историческите', function () {
    $old = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    Race::factory()->create(['season_id' => $old->id, 'jolpica_id' => 'estoril', 'circuit' => 'Estoril']);

    $this->get('/circuits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('circuits', 2)
            ->where('circuits.0.slug', 'monaco')      // активната първа
            ->where('circuits.0.is_active', true)
            ->where('circuits.1.is_active', false)
            ->has('counts'));
});

it('филтрира само активните писти', function () {
    $old = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    Race::factory()->create(['season_id' => $old->id, 'jolpica_id' => 'estoril', 'circuit' => 'Estoril']);

    $this->get('/circuits?filter=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeFilter', 'active')
            ->has('circuits', 1)
            ->where('circuits.0.slug', 'monaco'));
});

it('показва детайлната страница на писта с all-time класиране', function () {
    $this->get('/circuits/monaco')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Circuits/Show')
            ->where('circuit.name', 'Circuit de Monaco')
            ->has('standings', 1)
            ->has('records')
            ->has('lastWinners'));
});

it('връща 404 за несъществуваща писта', function () {
    $this->get('/circuits/nonexistent')->assertNotFound();
});
