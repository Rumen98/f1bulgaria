<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\Teams\CanonicalConstructorBackfiller;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->season = Season::factory()->current()->create();
});

function backfillTeamCanonical(): void
{
    app(CanonicalConstructorBackfiller::class)->backfill();
}

it('показва списъка с отбори', function () {
    Constructor::factory()->count(3)->create(['season_id' => $this->season->id]);
    backfillTeamCanonical();

    $this->get('/teams')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Index')
            ->has('teams', 3));
});

it('показва детайлната страница на отбор по slug', function () {
    $team = Constructor::factory()->create(['season_id' => $this->season->id, 'name' => 'Scuderia Ferrari', 'slug' => 'ferrari']);
    Driver::factory()->count(2)->create(['season_id' => $this->season->id, 'constructor_id' => $team->id]);
    backfillTeamCanonical();

    $this->get('/teams/ferrari')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Show')
            ->where('team.name', 'Scuderia Ferrari')
            ->where('team.is_active', true)
            ->has('drivers', 2)
            ->has('stats')
            ->has('stats.win_rate')
            ->has('stats.seasons'));
});

it('връща 404 за несъществуващ отбор', function () {
    $this->get('/teams/nonexistent')->assertNotFound();
});

it('резолва легендарен отбор извън текущия сезон', function () {
    $old = Season::factory()->create(['year' => 1995, 'is_current' => false]);
    $team = Constructor::factory()->create(['season_id' => $old->id, 'name' => 'Team Lotus', 'slug' => 'lotus']);
    $driver = Driver::factory()->create(['season_id' => $old->id, 'constructor_id' => $team->id]);
    $race = Race::factory()->create(['season_id' => $old->id]);
    Result::factory()->position(1)->create(['race_id' => $race->id, 'driver_id' => $driver->id, 'grid_position' => 1]);
    backfillTeamCanonical();

    $this->get('/teams/lotus')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Show')
            ->where('team.name', 'Team Lotus')
            ->where('team.is_active', false)
            ->where('stats.wins', 1)
            ->where('stats.poles', 1));
});

it('?season избира конкретен сезон на отбора', function () {
    $old = Season::factory()->create(['year' => 2014, 'is_current' => false]);
    Constructor::factory()->create(['season_id' => $old->id, 'name' => 'McLaren', 'slug' => 'mclaren']);
    Constructor::factory()->create(['season_id' => $this->season->id, 'name' => 'McLaren', 'slug' => 'mclaren']);
    backfillTeamCanonical();

    // По подразбиране → последният сезон.
    $this->get('/teams/mclaren')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedSeason', $this->season->year)
            ->where('seasons', [$this->season->year, 2014]));

    // ?season=2014 → конкретният сезон.
    $this->get('/teams/mclaren?season=2014')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedSeason', 2014)
            ->where('season', 2014));

    // Невалиден сезон → fallback към последния.
    $this->get('/teams/mclaren?season=1850')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedSeason', $this->season->year));
});

it('дава slug на новините на отбора, за да водят към нашата статия', function () {
    $team = Constructor::factory()->create(['season_id' => $this->season->id, 'name' => 'Ferrari', 'slug' => 'ferrari']);
    $item = TeamNewsItem::factory()->approved()->create(['constructor_id' => $team->id]);
    backfillTeamCanonical();

    $this->get('/teams/ferrari')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('news', 1)
            // Без slug картата пада към external_url и праща читателя навън.
            ->where('news.0.slug', $item->slug));

    // Слугът наистина резолва към нашата article страница.
    $this->get("/news/{$item->slug}")->assertOk();
});
