<?php

declare(strict_types=1);

use App\Models\Race;
use App\Models\Result;
use App\Models\Season;
use App\Services\Jolpica\ResultSyncService;
use Illuminate\Support\Facades\Http;

function driverBlock(): array
{
    return [
        'driverId' => 'verstappen',
        'code' => 'VER',
        'permanentNumber' => '1',
        'givenName' => 'Max',
        'familyName' => 'Verstappen',
        'nationality' => 'Dutch',
    ];
}

function constructorBlock(): array
{
    return ['constructorId' => 'red_bull', 'name' => 'Red Bull'];
}

beforeEach(function () {
    $this->season = Season::factory()->current()->create(['year' => 2024]);
    $this->race = Race::factory()->create([
        'season_id' => $this->season->id,
        'round' => 5,
        'has_sprint' => true,
    ]);

    Http::fake([
        '*/2024/5/results.json*' => Http::response(['MRData' => [
            'RaceTable' => ['Races' => [['Results' => [[
                'positionText' => '1', 'points' => '25', 'grid' => '1', 'status' => 'Finished',
                'Driver' => driverBlock(), 'Constructor' => constructorBlock(),
                'FastestLap' => ['rank' => '1'],
            ]]]]],
        ]]),
        '*/2024/5/sprint.json*' => Http::response(['MRData' => [
            'RaceTable' => ['Races' => [['SprintResults' => [[
                'positionText' => '1', 'points' => '8', 'grid' => '2', 'status' => 'Finished',
                'Driver' => driverBlock(), 'Constructor' => constructorBlock(),
            ]]]]],
        ]]),
        '*/2024/5/qualifying.json*' => Http::response(['MRData' => [
            'RaceTable' => ['Races' => [['QualifyingResults' => [[
                'position' => '1', 'Driver' => driverBlock(), 'Constructor' => constructorBlock(),
            ]]]]],
        ]]),
    ]);
});

it('синхронизира и главните, и спринт резултатите за спринт уикенд', function () {
    $stats = app(ResultSyncService::class)->sync($this->race);

    expect($stats['results'])->toBe(1)
        ->and($stats['sprint'])->toBe(1);

    expect(Result::where('session_type', 'race')->where('points', 25)->exists())->toBeTrue()
        ->and(Result::where('session_type', 'sprint')->where('points', 8)->exists())->toBeTrue();

    // pole се определя от квалификацията
    expect($this->race->fresh()->pole_driver_id)->not->toBeNull();
});

it('не дърпа спринт за уикенд без спринт', function () {
    $this->race->update(['has_sprint' => false]);

    $stats = app(ResultSyncService::class)->sync($this->race);

    expect($stats['sprint'])->toBe(0)
        ->and(Result::where('session_type', 'sprint')->exists())->toBeFalse();
});
