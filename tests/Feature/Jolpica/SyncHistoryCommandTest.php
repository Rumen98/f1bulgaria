<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use Illuminate\Support\Facades\Http;

/**
 * Пълен happy-path fake за един сезон (календар + резултати на 1 кръг).
 */
function fakeSeason(int $year): array
{
    return [
        "*/{$year}/constructors.json*" => Http::response(['MRData' => [
            'total' => '1',
            'ConstructorTable' => ['Constructors' => [
                ['constructorId' => 'mercedes', 'name' => 'Mercedes', 'nationality' => 'German'],
            ]],
        ]]),
        "*/{$year}/driverstandings.json*" => Http::response(['MRData' => [
            'StandingsTable' => ['StandingsLists' => [[
                'DriverStandings' => [[
                    'Driver' => ['driverId' => 'hamilton'],
                    'Constructors' => [['constructorId' => 'mercedes', 'name' => 'Mercedes']],
                ]],
            ]]],
        ]]),
        "*/{$year}/drivers.json*" => Http::response(['MRData' => [
            'total' => '1',
            'DriverTable' => ['Drivers' => [[
                'driverId' => 'hamilton', 'code' => 'HAM', 'permanentNumber' => '44',
                'givenName' => 'Lewis', 'familyName' => 'Hamilton', 'nationality' => 'British',
            ]]],
        ]]),
        "*/ergast/f1/{$year}.json*" => Http::response(['MRData' => [
            'total' => '1',
            'RaceTable' => ['Races' => [[
                'season' => (string) $year, 'round' => '1', 'raceName' => 'Australian Grand Prix',
                'Circuit' => [
                    'circuitId' => 'albert_park', 'circuitName' => 'Albert Park',
                    'Location' => ['country' => 'Australia'],
                ],
                'date' => "{$year}-03-17", 'time' => '06:00:00Z',
            ]]],
        ]]),
        "*/{$year}/1/results.json*" => Http::response(['MRData' => [
            'RaceTable' => ['Races' => [['Results' => [[
                'positionText' => '1', 'points' => '25', 'grid' => '1', 'status' => 'Finished',
                'Driver' => ['driverId' => 'hamilton', 'code' => 'HAM', 'givenName' => 'Lewis', 'familyName' => 'Hamilton', 'nationality' => 'British'],
                'Constructor' => ['constructorId' => 'mercedes', 'name' => 'Mercedes'],
                'FastestLap' => ['rank' => '1'],
            ]]]]],
        ]]),
        "*/{$year}/1/qualifying.json*" => Http::response(['MRData' => [
            'RaceTable' => ['Races' => [['QualifyingResults' => [[
                'position' => '1',
                'Driver' => ['driverId' => 'hamilton', 'code' => 'HAM', 'givenName' => 'Lewis', 'familyName' => 'Hamilton', 'nationality' => 'British'],
                'Constructor' => ['constructorId' => 'mercedes', 'name' => 'Mercedes'],
            ]]]]],
        ]]),
    ];
}

it('синхронизира исторически сезон с резултати', function () {
    Http::fake(fakeSeason(2019));

    $this->artisan('f1:sync-history', ['from' => 2019, 'to' => 2019, '--throttle' => 0])
        ->assertSuccessful();

    expect(Season::where('year', 2019)->exists())->toBeTrue()
        ->and(Race::whereRelation('season', 'year', 2019)->count())->toBe(1)
        ->and(Result::where('session_type', 'race')->where('points', 25)->exists())->toBeTrue();

    // pole се определя от квалификацията — нужно за Task 3
    expect(Race::whereRelation('season', 'year', 2019)->first()->pole_driver_id)->not->toBeNull();
});

it('продължава със следващите сезони при провален сезон', function () {
    Http::fake([
        // 2018: календарът пада (400) → сезонът се проваля
        '*/2018/constructors.json*' => Http::response('', 400),
        ...fakeSeason(2019),
    ]);

    $this->artisan('f1:sync-history', ['from' => 2018, 'to' => 2019, '--throttle' => 0])
        ->assertSuccessful();

    // 2018 се проваля на календара → 0 състезания; 2019 минава докрай.
    expect(Race::whereRelation('season', 'year', 2018)->count())->toBe(0)
        ->and(Race::whereRelation('season', 'year', 2019)->count())->toBe(1)
        ->and(Result::where('session_type', 'race')->where('points', 25)->exists())->toBeTrue();
});

it('прескача годините от --skip', function () {
    Http::fake(fakeSeason(2019));

    $this->artisan('f1:sync-history', ['from' => 2019, 'to' => 2019, '--skip' => '2019', '--throttle' => 0])
        ->assertSuccessful();

    expect(Race::count())->toBe(0);
    Http::assertNothingSent();
});
