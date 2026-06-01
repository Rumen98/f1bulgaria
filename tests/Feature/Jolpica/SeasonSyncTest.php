<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use App\Services\Jolpica\SeasonSyncService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/2024/constructors.json*' => Http::response(['MRData' => [
            'total' => '1',
            'ConstructorTable' => ['Constructors' => [
                ['constructorId' => 'mclaren', 'name' => 'McLaren', 'nationality' => 'British'],
            ]],
        ]]),
        '*/2024/driverstandings.json*' => Http::response(['MRData' => [
            'StandingsTable' => ['StandingsLists' => [[
                'DriverStandings' => [[
                    'Driver' => ['driverId' => 'norris'],
                    'Constructors' => [['constructorId' => 'mclaren', 'name' => 'McLaren']],
                ]],
            ]]],
        ]]),
        '*/2024/drivers.json*' => Http::response(['MRData' => [
            'total' => '1',
            'DriverTable' => ['Drivers' => [[
                'driverId' => 'norris',
                'permanentNumber' => '4',
                'code' => 'NOR',
                'givenName' => 'Lando',
                'familyName' => 'Norris',
                'nationality' => 'British',
            ]]],
        ]]),
        '*/ergast/f1/2024.json*' => Http::response(['MRData' => [
            'total' => '1',
            'RaceTable' => ['Races' => [[
                'season' => '2024',
                'round' => '1',
                'raceName' => 'Bahrain Grand Prix',
                'Circuit' => [
                    'circuitId' => 'bahrain',
                    'circuitName' => 'Bahrain International Circuit',
                    'Location' => ['country' => 'Bahrain'],
                ],
                'date' => '2024-03-02',
                'time' => '15:00:00Z',
                'Qualifying' => ['date' => '2024-03-01', 'time' => '16:00:00Z'],
                'FirstPractice' => ['date' => '2024-02-29', 'time' => '11:30:00Z'],
            ]]],
        ]]),
    ]);
});

it('синхронизира сезон с конструктори, пилоти и състезания', function () {
    $stats = app(SeasonSyncService::class)->sync(2024);

    expect($stats)->toMatchArray(['constructors' => 1, 'drivers' => 1, 'races' => 1]);

    $driver = Driver::query()->where('jolpica_id', 'norris')->first();
    expect($driver)->not->toBeNull()
        ->and($driver->driver_code)->toBe('NOR')
        ->and($driver->country_code)->toBe('GBR')
        ->and($driver->constructor->jolpica_id)->toBe('mclaren');

    $race = Race::query()->where('round', 1)->first();
    expect($race->name)->toBe('Bahrain Grand Prix')
        ->and($race->qualifying_datetime_utc->toIso8601String())->toContain('2024-03-01');
});

it('създава сесии за състезанието', function () {
    app(SeasonSyncService::class)->sync(2024);

    $race = Race::query()->where('round', 1)->first();

    expect($race->sessions()->pluck('type')->map->value->all())
        ->toContain('fp1', 'qualifying', 'race');
});

it('е идемпотентен — повторният синхрон не дублира записи', function () {
    $service = app(SeasonSyncService::class);
    $service->sync(2024);
    $service->sync(2024);

    expect(Constructor::query()->count())->toBe(1)
        ->and(Driver::query()->count())->toBe(1)
        ->and(Race::query()->count())->toBe(1)
        ->and(RaceSession::query()->where('type', 'race')->count())->toBe(1);
});
