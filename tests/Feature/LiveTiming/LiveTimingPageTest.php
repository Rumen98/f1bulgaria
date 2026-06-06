<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Season;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
});

function fakeLiveSession(): void
{
    Http::fake([
        '*/sessions*' => Http::response([[
            'session_key' => 9999, 'session_name' => 'Qualifying', 'session_type' => 'Qualifying',
            'date_start' => now()->subMinutes(10)->toIso8601String(),
            'date_end' => now()->addMinutes(20)->toIso8601String(),
            'circuit_short_name' => 'Monaco',
        ]]),
        '*/drivers*' => Http::response([
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max Verstappen', 'team_name' => 'Red Bull', 'team_colour' => '3671C6'],
        ]),
        '*/laps*' => Http::response([
            ['driver_number' => 1, 'lap_number' => 2, 'lap_duration' => 72.345],
        ]),
        '*/stints*' => Http::response([['driver_number' => 1, 'lap_start' => 1, 'compound' => 'SOFT']]),
    ]);
}

it('/live показва live класиране при активна сесия', function () {
    fakeLiveSession();

    $this->get('/live')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Live/Index')
            ->where('session.name', 'Qualifying')
            ->where('session.circuit', 'Monaco')
            ->has('standings', 1)
            ->where('standings.0.acronym', 'VER'));
});

it('/live показва no-session състояние когато няма сесия', function () {
    Http::fake(['*' => Http::response([])]);

    $season = Season::factory()->current()->create();
    Race::factory()->create(['season_id' => $season->id, 'name' => 'Гран При на Бахрейн', 'race_datetime_utc' => Carbon::now()->addDays(5)]);

    $this->get('/live')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Live/Index')
            ->where('session', null)
            ->where('nextRace.name', 'Гран При на Бахрейн'));
});

it('/live/refresh връща JSON със standings', function () {
    fakeLiveSession();

    $this->getJson('/live/refresh')
        ->assertOk()
        ->assertJsonPath('session.name', 'Qualifying')
        ->assertJsonPath('standings.0.acronym', 'VER')
        ->assertJsonStructure(['session', 'standings', 'updated_at']);
});

it('/live/refresh връща session=null при API грешка (graceful)', function () {
    Http::fake(['*' => Http::response('error', 500)]);

    $this->getJson('/live/refresh')
        ->assertOk()
        ->assertJsonPath('session', null)
        ->assertJsonPath('standings', []);
});

it('третира приключила сесия като не-активна', function () {
    Http::fake([
        '*/sessions*' => Http::response([[
            'session_key' => 8888, 'session_name' => 'Race', 'session_type' => 'Race',
            'date_start' => now()->subHours(5)->toIso8601String(),
            'date_end' => now()->subHours(3)->toIso8601String(),
            'circuit_short_name' => 'Spa',
        ]]),
    ]);

    $this->getJson('/live/refresh')->assertOk()->assertJsonPath('session', null);
});
