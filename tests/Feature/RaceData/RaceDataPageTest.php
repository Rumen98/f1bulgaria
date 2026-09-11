<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['features.data_recap' => true]);
});

function raceWithRecap(array $recap = []): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $race = Race::factory()->create([
        'season_id' => $season->id,
        'round' => 13,
        'name' => 'Italian Grand Prix',
        'jolpica_id' => 'monza',
        'circuit' => 'Autodromo Nazionale Monza',
        'race_datetime_utc' => now()->subDays(2),
    ]);

    RaceDataRecap::factory()->create(array_merge(['race_id' => $race->id], $recap));

    return $race;
}

it('показва списъка с готовите анализи', function () {
    raceWithRecap();

    $this->get('/danni')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RaceData/Index')
            ->has('races', 1)
            ->where('races.0.name', 'Гран при на Италия'));
});

it('не показва паднал опит в списъка', function () {
    $season = Season::factory()->create(['year' => 2026]);
    $race = Race::factory()->create(['season_id' => $season->id, 'race_datetime_utc' => now()->subDay()]);
    RaceDataRecap::factory()->failed()->create(['race_id' => $race->id]);

    $this->get('/danni')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('races', 0));
});

it('показва страницата с графиките', function () {
    $race = raceWithRecap();

    $this->get("/danni/{$race->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RaceData/Show')
            ->where('race.name', 'Гран при на Италия')
            ->has('facts.winner')
            ->has('charts')
            ->has('body', 2));
});

it('връща 404 за състезание без готов рекап', function () {
    $season = Season::factory()->create(['year' => 2026]);
    $race = Race::factory()->create(['season_id' => $season->id]);

    $this->get("/danni/{$race->id}")->assertNotFound();
});

it('връзва съседните кръгове само през тези с рекап', function () {
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);

    $earlier = Race::factory()->create(['season_id' => $season->id, 'round' => 12, 'race_datetime_utc' => now()->subDays(20)]);
    $middle = Race::factory()->create(['season_id' => $season->id, 'round' => 13, 'race_datetime_utc' => now()->subDays(10)]);
    // Този няма рекап — стрелката не бива да води към 404.
    Race::factory()->create(['season_id' => $season->id, 'round' => 14, 'race_datetime_utc' => now()->subDays(2)]);

    RaceDataRecap::factory()->create(['race_id' => $earlier->id]);
    RaceDataRecap::factory()->create(['race_id' => $middle->id]);

    $this->get("/danni/{$middle->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('neighbours.prev.id', $earlier->id)
            ->where('neighbours.next', null));
});

it('страницата на състезанието води към анализа само когато има такъв', function () {
    $race = raceWithRecap();

    $this->get("/races/{$race->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasDataRecap', true));

    $season = Season::factory()->create(['year' => 2025]);
    $other = Race::factory()->create(['season_id' => $season->id]);

    $this->get("/races/{$other->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasDataRecap', false));
});

it('началната страница показва последния анализ', function () {
    raceWithRecap();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('dataRecap')
            ->where('dataRecap.race', 'Гран при на Италия'));
});

it('търсачката намира анализа по име на пистата', function () {
    raceWithRecap();

    $response = $this->get('/tarsene?q=Monza')->assertOk();

    $group = collect($response->viewData('page')['props']['groups'])->firstWhere('key', 'racedata');

    expect(array_column($group['items'], 'title'))->toContain('Данните от Гран при на Италия');
});

it('sitemap-ът включва само готовите анализи', function () {
    $race = raceWithRecap();

    $season = Season::factory()->create(['year' => 2025]);
    $failed = Race::factory()->create(['season_id' => $season->id, 'race_datetime_utc' => now()->subDays(30)]);
    RaceDataRecap::factory()->failed()->create(['race_id' => $failed->id]);

    $this->artisan('sitemap:generate')->assertSuccessful();

    $xml = file_get_contents(public_path('sitemap-content.xml'));

    expect($xml)->toContain("/danni/{$race->id}")
        ->and($xml)->not->toContain("/danni/{$failed->id}");
});

it('филтрира анализите по сезон и предлага годините', function () {
    $old = Season::factory()->create(['year' => 2024]);
    $oldRace = Race::factory()->create([
        'season_id' => $old->id,
        'name' => 'Monaco Grand Prix',
        'jolpica_id' => 'monaco',
        'race_datetime_utc' => now()->subYears(2),
    ]);
    RaceDataRecap::factory()->create(['race_id' => $oldRace->id]);

    raceWithRecap();   // 2026

    // Без параметър: най-новият сезон.
    $this->get('/danni')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season', 2026)
            ->where('seasons', [2026, 2024])
            ->has('races', 1)
            ->where('races.0.name', 'Гран при на Италия'));

    $this->get('/danni?sezon=2024')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season', 2024)
            ->has('races', 1)
            ->where('races.0.name', 'Гран при на Монако'));
});

it('непозната година пада към най-новия сезон, вместо да дава празна страница', function () {
    raceWithRecap();

    $this->get('/danni?sezon=1999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('season', 2026)->has('races', 1));
});

it('началната показва последното състезание, а не последно сметнатия рекап', function () {
    // Наваксването назад преизчислява стари кръгове, тоест generated_at на
    // миналогодишен кръг става по-нов от този на вчерашния. Точно това изкара
    // Абу Даби 2025 на началната страница.
    $old = Season::factory()->create(['year' => 2025]);
    $oldRace = Race::factory()->create([
        'season_id' => $old->id,
        'round' => 24,
        'name' => 'Abu Dhabi Grand Prix',
        'jolpica_id' => 'yas_marina',
        'race_datetime_utc' => now()->subYear(),
    ]);

    $latest = raceWithRecap();

    // Старият рекап е сметнат ПОСЛЕДЕН.
    RaceDataRecap::factory()->create([
        'race_id' => $oldRace->id,
        'generated_at' => now()->addMinute(),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dataRecap.race_id', $latest->id)
            ->where('dataRecap.race', 'Гран при на Италия'));
});

it('картончето пази победителя и точките след стесняването на заявката', function () {
    // Индексът вече не чете `charts`. Ако стесняването отреже и `facts`,
    // списъкът мълчаливо олеква — затова се проверява точно това, което
    // картончето вади оттам.
    raceWithRecap();

    $this->get('/danni')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('races.0.winner.name', 'Ландо Норис')
            ->has('races.0.bullets', 2));
});

it('двубоят тръгва от любимия пилот на влезлия', function () {
    $race = raceWithRecap();
    $driver = Driver::factory()->create(['permanent_number' => 16]);

    $this->actingAs(User::factory()->create(['favorite_driver_id' => $driver->id]))
        ->get(route('racedata.show', $race->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preselect', 16));
});

it('гостът няма предварително избран пилот', function () {
    $race = raceWithRecap();

    $this->get(route('racedata.show', $race->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preselect', null));
});

it('профил без избран пилот също няма предварителен избор', function () {
    $race = raceWithRecap();

    $this->actingAs(User::factory()->create(['favorite_driver_id' => null]))
        ->get(route('racedata.show', $race->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preselect', null));
});
