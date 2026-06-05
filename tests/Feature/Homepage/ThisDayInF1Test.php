<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Homepage\ThisDayInF1Service;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

it('връща историческите състезания на дадена дата, най-скорошните първо', function () {
    $s1 = Season::factory()->create(['year' => 1990, 'is_current' => false]);
    $s2 = Season::factory()->create(['year' => 2020, 'is_current' => false]);
    $team = Constructor::factory()->create(['season_id' => $s2->id, 'name' => 'Mercedes', 'color_hex' => '#00d2be']);
    $senna = Driver::factory()->create(['season_id' => $s1->id, 'first_name' => 'Ayrton', 'last_name' => 'Senna', 'slug' => 'ayrton-senna']);
    $hamilton = Driver::factory()->create(['season_id' => $s2->id, 'constructor_id' => $team->id, 'first_name' => 'Lewis', 'last_name' => 'Hamilton', 'slug' => 'lewis-hamilton']);

    $r1 = Race::factory()->create(['season_id' => $s1->id, 'name' => 'Гран При на Монако', 'jolpica_id' => 'monaco', 'race_datetime_utc' => Carbon::create(1990, 7, 15, 14)]);
    $r2 = Race::factory()->create(['season_id' => $s2->id, 'name' => 'Гран При на Испания', 'jolpica_id' => 'spain', 'race_datetime_utc' => Carbon::create(2020, 7, 15, 14)]);
    $other = Race::factory()->create(['season_id' => $s2->id, 'race_datetime_utc' => Carbon::create(2020, 7, 16, 14)]); // друг ден

    Result::factory()->position(1)->create(['race_id' => $r1->id, 'driver_id' => $senna->id]);
    Result::factory()->position(1)->create(['race_id' => $r2->id, 'driver_id' => $hamilton->id]);
    Result::factory()->position(1)->create(['race_id' => $other->id, 'driver_id' => $hamilton->id]);

    $events = app(ThisDayInF1Service::class)->forDate(Carbon::create(2026, 7, 15));

    expect($events)->toHaveCount(2)
        ->and($events->first()['year'])->toBe(2020)
        ->and($events->first()['winner'])->toBe('Lewis Hamilton')
        ->and($events->first()['team'])->toBe('Mercedes')
        ->and($events->first()['circuit_slug'])->toBe('spain')
        ->and($events->last()['year'])->toBe(1990)
        ->and($events->last()['winner'])->toBe('Ayrton Senna');
});

it('връща празна колекция за дата без състезания', function () {
    $events = app(ThisDayInF1Service::class)->forDate(Carbon::create(2026, 12, 31));

    expect($events)->toBeEmpty();
});

it('началната страница подава thisDay prop', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')->has('thisDay'));
});
