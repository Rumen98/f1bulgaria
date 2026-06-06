<?php

declare(strict_types=1);

use App\Services\LiveTiming\OpenF1Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush(); // кешът на клиента да не преплита тестовете
});

function client(): OpenF1Client
{
    return app(OpenF1Client::class);
}

it('разпознава текущата сесия', function () {
    Http::fake(['*/sessions*' => Http::response([[
        'session_key' => 9999,
        'session_name' => 'Qualifying',
        'session_type' => 'Qualifying',
        'date_start' => '2026-06-06T14:00:00+00:00',
        'date_end' => '2026-06-06T15:00:00+00:00',
        'meeting_key' => 1234,
        'circuit_short_name' => 'Monaco',
    ]])]);

    $session = client()->getCurrentSession();

    expect($session)->not->toBeNull()
        ->and($session['key'])->toBe(9999)
        ->and($session['name'])->toBe('Qualifying')
        ->and($session['circuit_short_name'])->toBe('Monaco')
        ->and($session['date_start']?->format('H:i'))->toBe('14:00');
});

it('връща null за текуща сесия при празен отговор', function () {
    Http::fake(['*/sessions*' => Http::response([])]);

    expect(client()->getCurrentSession())->toBeNull();
});

it('парсва пилотите със #-префикс на цвета', function () {
    Http::fake(['*/drivers*' => Http::response([
        ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max Verstappen', 'team_name' => 'Red Bull', 'team_colour' => '3671C6'],
        ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis Hamilton', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ['invalid' => 'no driver_number'],
    ])]);

    $drivers = client()->getSessionDrivers(9999);

    expect($drivers)->toHaveCount(2)
        ->and($drivers->first()['name_acronym'])->toBe('VER')
        ->and($drivers->first()['team_colour'])->toBe('#3671C6');
});

it('парсва обиколките', function () {
    Http::fake(['*/laps*' => Http::response([
        ['driver_number' => 1, 'lap_number' => 5, 'lap_duration' => 72.345],
        ['driver_number' => 44, 'lap_number' => 5, 'lap_duration' => 72.901],
        ['missing_driver' => true],
    ])]);

    expect(client()->getLatestLaps(9999))->toHaveCount(2);
});

it('връща празна колекция при timeout (graceful)', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(client()->getLatestLaps(9999))->toBeEmpty()
        ->and(client()->getSessionDrivers(9999))->toBeEmpty()
        ->and(client()->getCurrentSession())->toBeNull();
});

it('връща празна колекция при HTTP 500 (graceful)', function () {
    Http::fake(['*' => Http::response('error', 500)]);

    expect(client()->getStints(9999))->toBeEmpty()
        ->and(client()->getCurrentSession())->toBeNull();
});

it('праща OAuth Bearer токен когато има кредитали (за живи сесии)', function () {
    config(['services.openf1.username' => 'u@x.bg', 'services.openf1.password' => 'p']);
    Http::fake([
        '*/token' => Http::response(['access_token' => 'oauth-token', 'expires_in' => 3600]),
        '*/laps*' => Http::response([['driver_number' => 1, 'lap_number' => 1]]),
    ]);

    client()->getLatestLaps(9999);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/laps')
        && $request->hasHeader('Authorization', 'Bearer oauth-token'));
});

it('НЕ праща Authorization когато няма кредитали', function () {
    config(['services.openf1.username' => null, 'services.openf1.password' => null]);
    Http::fake(['*/laps*' => Http::response([])]);

    client()->getLatestLaps(9999);

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});
