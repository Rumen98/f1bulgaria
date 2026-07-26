<?php

declare(strict_types=1);

use App\Services\LiveTiming\LiveStandingsBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

function fakeSession(array $drivers, array $laps, array $stints = [], array $positions = [], array $intervals = []): void
{
    Http::fake([
        '*/drivers*' => Http::response($drivers),
        '*/laps*' => Http::response($laps),
        '*/stints*' => Http::response($stints),
        '*/position*' => Http::response($positions),
        '*/intervals*' => Http::response($intervals),
    ]);
}

it('подрежда по най-добра обиколка и смята интервалите', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max Verstappen', 'team_name' => 'Red Bull', 'team_colour' => '3671C6'],
            ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis Hamilton', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ],
        laps: [
            ['driver_number' => 1, 'lap_number' => 1, 'lap_duration' => 73.500, 'duration_sector_1' => 24.0, 'duration_sector_2' => 25.0, 'duration_sector_3' => 24.5],
            ['driver_number' => 1, 'lap_number' => 2, 'lap_duration' => 72.345, 'duration_sector_1' => 23.5, 'duration_sector_2' => 24.8, 'duration_sector_3' => 24.045],
            ['driver_number' => 44, 'lap_number' => 1, 'lap_duration' => 72.901, 'duration_sector_1' => 23.8, 'duration_sector_2' => 24.9, 'duration_sector_3' => 24.201],
        ],
        stints: [
            ['driver_number' => 1, 'lap_start' => 1, 'compound' => 'SOFT'],
            ['driver_number' => 44, 'lap_start' => 1, 'compound' => 'MEDIUM'],
        ],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Qualifying');

    expect($rows)->toHaveCount(2);

    $leader = $rows->first();
    expect($leader['driver_number'])->toBe(1)
        ->and($leader['position'])->toBe(1)
        ->and($leader['best_lap_time'])->toBe('1:12.345')   // най-добрата от двете обиколки
        ->and($leader['best_lap_number'])->toBe(2)
        ->and($leader['gap_to_leader'])->toBe('—')
        ->and($leader['current_tire'])->toBe('SOFT')
        ->and($leader['laps_completed'])->toBe(2)
        ->and($leader['sector1_best'])->toBe(23.5);

    $second = $rows->last();
    expect($second['driver_number'])->toBe(44)
        ->and($second['position'])->toBe(2)
        ->and($second['gap_to_leader'])->toBe('+0.556'); // 72.901 - 72.345
});

it('слага пилоти без време накрая', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max Verstappen', 'team_name' => 'Red Bull', 'team_colour' => '3671C6'],
            ['driver_number' => 2, 'name_acronym' => 'SAR', 'full_name' => 'No Lap', 'team_name' => 'X', 'team_colour' => '000000'],
        ],
        laps: [
            ['driver_number' => 1, 'lap_number' => 1, 'lap_duration' => 72.0],
            ['driver_number' => 2, 'lap_number' => 1, 'lap_duration' => 0], // невалидна
        ],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Practice');

    expect($rows->first()['driver_number'])->toBe(1)
        ->and($rows->last()['driver_number'])->toBe(2)
        ->and($rows->last()['best_lap_time'])->toBeNull();
});

it('маркира лилаво за сесийно-най-добра обиколка и сектори', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max', 'team_name' => 'RB', 'team_colour' => '3671C6'],
            ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ],
        laps: [
            // VER: най-добра обиколка + най-добри S1 и S2
            ['driver_number' => 1, 'lap_number' => 1, 'lap_duration' => 72.0, 'duration_sector_1' => 23.0, 'duration_sector_2' => 24.0, 'duration_sector_3' => 25.0],
            // HAM: по-бавна обиколка, но най-добър S3
            ['driver_number' => 44, 'lap_number' => 1, 'lap_duration' => 72.5, 'duration_sector_1' => 23.5, 'duration_sector_2' => 24.5, 'duration_sector_3' => 24.5],
        ],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Qualifying');
    $ver = $rows->firstWhere('driver_number', 1);
    $ham = $rows->firstWhere('driver_number', 44);

    expect($ver['is_overall_best'])->toBeTrue()
        ->and($ver['sector1_overall'])->toBeTrue()
        ->and($ver['sector2_overall'])->toBeTrue()
        ->and($ver['sector3_overall'])->toBeFalse()
        ->and($ham['is_overall_best'])->toBeFalse()
        ->and($ham['sector3_overall'])->toBeTrue();
});

it('връща празна колекция когато няма пилоти (API недостъпен)', function () {
    fakeSession(drivers: [], laps: []);

    expect(app(LiveStandingsBuilder::class)->build(9999, 'Qualifying'))->toBeEmpty();
});

it('в състезание подрежда по позиция на трасето, а не по най-бърза обиколка', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max', 'team_name' => 'RB', 'team_colour' => '3671C6'],
            ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ],
        laps: [
            // HAM има най-бързата обиколка, но VER води състезанието.
            ['driver_number' => 1, 'lap_number' => 12, 'lap_duration' => 92.500],
            ['driver_number' => 44, 'lap_number' => 12, 'lap_duration' => 91.200],
        ],
        positions: [
            // По-ранни записи с обратна подредба — трябва да се вземе последният.
            ['driver_number' => 44, 'position' => 1, 'date' => '2026-07-26T13:00:00+00:00'],
            ['driver_number' => 1, 'position' => 2, 'date' => '2026-07-26T13:00:00+00:00'],
            ['driver_number' => 1, 'position' => 1, 'date' => '2026-07-26T13:20:00+00:00'],
            ['driver_number' => 44, 'position' => 2, 'date' => '2026-07-26T13:20:00+00:00'],
        ],
        intervals: [
            ['driver_number' => 1, 'gap_to_leader' => null, 'interval' => null, 'date' => '2026-07-26T13:20:04+00:00'],
            ['driver_number' => 44, 'gap_to_leader' => 5.123, 'interval' => 5.123, 'date' => '2026-07-26T13:20:04+00:00'],
        ],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Race');

    $leader = $rows->first();
    expect($leader['driver_number'])->toBe(1)
        ->and($leader['position'])->toBe(1)
        ->and($leader['gap_to_leader'])->toBe('—')
        // Лилавото остава при най-бързата обиколка, независимо от позицията.
        ->and($leader['is_overall_best'])->toBeFalse();

    $second = $rows->last();
    expect($second['driver_number'])->toBe(44)
        ->and($second['position'])->toBe(2)
        ->and($second['gap_to_leader'])->toBe('+5.123')
        ->and($second['is_overall_best'])->toBeTrue();
});

it('показва изоставане с обиколка на български', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max', 'team_name' => 'RB', 'team_colour' => '3671C6'],
            ['driver_number' => 2, 'name_acronym' => 'LAW', 'full_name' => 'Liam', 'team_name' => 'RB', 'team_colour' => '3671C6'],
        ],
        laps: [],
        positions: [
            ['driver_number' => 1, 'position' => 1, 'date' => '2026-07-26T13:20:00+00:00'],
            ['driver_number' => 2, 'position' => 2, 'date' => '2026-07-26T13:20:00+00:00'],
        ],
        intervals: [
            ['driver_number' => 2, 'gap_to_leader' => '+1 LAP', 'interval' => '+1 LAP', 'date' => '2026-07-26T13:20:04+00:00'],
        ],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Race');

    expect($rows->last()['gap_to_leader'])->toBe('+1 об.');
});

it('пада към подредба по обиколка когато няма позиционни данни', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max', 'team_name' => 'RB', 'team_colour' => '3671C6'],
            ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ],
        laps: [
            ['driver_number' => 1, 'lap_number' => 1, 'lap_duration' => 92.500],
            ['driver_number' => 44, 'lap_number' => 1, 'lap_duration' => 91.200],
        ],
        positions: [],
    );

    $rows = app(LiveStandingsBuilder::class)->build(9999, 'Race');

    expect($rows->first()['driver_number'])->toBe(44)
        ->and($rows->first()['gap_to_leader'])->toBe('—')
        ->and($rows->last()['gap_to_leader'])->toBe('+1.300');
});

it('спринтът също се подрежда по позиция на трасето', function () {
    fakeSession(
        drivers: [
            ['driver_number' => 1, 'name_acronym' => 'VER', 'full_name' => 'Max', 'team_name' => 'RB', 'team_colour' => '3671C6'],
            ['driver_number' => 44, 'name_acronym' => 'HAM', 'full_name' => 'Lewis', 'team_name' => 'Ferrari', 'team_colour' => 'E8002D'],
        ],
        laps: [
            ['driver_number' => 1, 'lap_number' => 3, 'lap_duration' => 92.500],
            ['driver_number' => 44, 'lap_number' => 3, 'lap_duration' => 91.200],
        ],
        positions: [
            ['driver_number' => 1, 'position' => 1, 'date' => '2026-07-26T13:20:00+00:00'],
            ['driver_number' => 44, 'position' => 2, 'date' => '2026-07-26T13:20:00+00:00'],
        ],
    );

    // OpenF1 дава session_type „Race“ и за спринта.
    expect(app(LiveStandingsBuilder::class)->build(9999, 'Race')->first()['driver_number'])->toBe(1);
});
