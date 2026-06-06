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

it('началната страница показва live банер при активна сесия (с кредитали)', function () {
    config(['services.openf1.username' => 'u@x.bg', 'services.openf1.password' => 'p']);
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/sessions*' => Http::response([[
            'session_key' => 9999, 'session_name' => 'Qualifying', 'session_type' => 'Qualifying',
            'date_start' => now()->subMinutes(10)->toIso8601String(),
            'date_end' => now()->addMinutes(20)->toIso8601String(),
            'circuit_short_name' => 'Monaco',
        ]]),
    ]);
    Season::factory()->current()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('liveSession.name', 'Qualifying'));
});

it('началната страница няма live банер без кредитали (не прави заявка)', function () {
    config(['services.openf1.username' => null, 'services.openf1.password' => null]);
    Season::factory()->current()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('liveSession', null));
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

it('маркира квалификацията с cutoff граници', function () {
    fakeLiveSession(); // session_type = Qualifying

    $this->getJson('/live/refresh')
        ->assertOk()
        ->assertJsonPath('session.is_qualifying', true)
        ->assertJsonPath('session.cutoffs', [15, 10]);
});

it('не маркира практиката като квалификация', function () {
    Http::fake([
        '*/sessions*' => Http::response([[
            'session_key' => 7777, 'session_name' => 'Practice 1', 'session_type' => 'Practice',
            'date_start' => now()->subMinutes(5)->toIso8601String(),
            'date_end' => now()->addMinutes(55)->toIso8601String(),
            'circuit_short_name' => 'Spa',
        ]]),
        '*/drivers*' => Http::response([]),
        '*/laps*' => Http::response([]),
        '*/stints*' => Http::response([]),
    ]);

    $this->getJson('/live/refresh')
        ->assertOk()
        ->assertJsonPath('session.is_qualifying', false)
        ->assertJsonPath('session.cutoffs', []);
});
