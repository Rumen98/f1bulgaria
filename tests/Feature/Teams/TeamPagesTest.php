<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
});

it('показва списъка с отбори', function () {
    Constructor::factory()->count(3)->create(['season_id' => $this->season->id]);

    $this->get('/teams')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Index')
            ->has('teams', 3));
});

it('показва детайлната страница на отбор по slug', function () {
    $team = Constructor::factory()->create(['season_id' => $this->season->id, 'name' => 'Scuderia Ferrari', 'slug' => 'ferrari']);
    Driver::factory()->count(2)->create(['season_id' => $this->season->id, 'constructor_id' => $team->id]);

    $this->get('/teams/ferrari')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Show')
            ->where('team.name', 'Scuderia Ferrari')
            ->has('drivers', 2)
            ->has('stats'));
});

it('връща 404 за несъществуващ отбор', function () {
    $this->get('/teams/nonexistent')->assertNotFound();
});
