<?php

declare(strict_types=1);

use App\Models\Race;
use App\Services\RaceData\CircuitGeometry;
use App\Services\RaceData\RaceChartsBuilder;
use App\Services\RaceData\RaceDataBundle;
use Illuminate\Support\Facades\Http;

/** @return array<int, array<string, mixed>> */
function seriesDrivers(): array
{
    return [
        ['driver_number' => 1, 'name_acronym' => 'D01', 'full_name' => 'Driver 1', 'team_name' => 'Test Racing', 'team_colour' => 'FF8000'],
        ['driver_number' => 2, 'name_acronym' => 'D02', 'full_name' => 'Driver 2', 'team_name' => 'Test Racing', 'team_colour' => 'FF8000'],
        ['driver_number' => 3, 'name_acronym' => 'D03', 'full_name' => 'Driver 3', 'team_name' => 'Test Racing', 'team_colour' => 'FF8000'],
    ];
}

/**
 * Обиколки на четирима пилоти с РАЗЛИЧНО темпо, тоест с различни прозорци по
 * време — само така се вижда дали смяната на позиция е вързана за обиколките
 * на правилния пилот.
 *
 * @return array<int, array<string, mixed>>
 */
function seriesLaps(): array
{
    $at = fn (string $time): string => "2026-09-06T{$time}+00:00";

    return [
        // Победителят кара по 40 секунди на обиколка.
        ['driver_number' => 3, 'lap_number' => 1, 'lap_duration' => 40.0, 'date_start' => $at('13:00:00')],
        ['driver_number' => 3, 'lap_number' => 2, 'lap_duration' => 40.0, 'date_start' => $at('13:00:40')],
        ['driver_number' => 3, 'lap_number' => 3, 'lap_duration' => 40.5, 'date_start' => $at('13:01:20')],
        // Вторият — по 80, тоест в 13:01:00 е още в първата си обиколка.
        ['driver_number' => 1, 'lap_number' => 1, 'lap_duration' => 80.1234, 'date_start' => $at('13:00:00')],
        ['driver_number' => 1, 'lap_number' => 2, 'lap_duration' => 82.0, 'date_start' => $at('13:01:20'), 'is_pit_out_lap' => true],
        ['driver_number' => 1, 'lap_number' => 3, 'lap_duration' => 79.0, 'date_start' => $at('13:02:42')],
        // Третият губи времето на последната си обиколка — дупка в данните.
        ['driver_number' => 2, 'lap_number' => 1, 'lap_duration' => 90.0, 'date_start' => $at('13:00:00')],
        ['driver_number' => 2, 'lap_number' => 2, 'lap_duration' => 91.0, 'date_start' => $at('13:01:30')],
        ['driver_number' => 2, 'lap_number' => 3, 'lap_duration' => null, 'date_start' => $at('13:03:01')],
        // Пилот без нито едно свое време. Обиколка 9 няма и медиана.
        ['driver_number' => 9, 'lap_number' => 1, 'lap_duration' => null, 'date_start' => $at('13:00:00')],
        ['driver_number' => 9, 'lap_number' => 2, 'lap_duration' => null, 'date_start' => $at('13:01:40')],
        ['driver_number' => 9, 'lap_number' => 9, 'lap_duration' => null, 'date_start' => $at('13:10:00')],
    ];
}

/** @return array<int, array<string, mixed>> */
function seriesOvertakes(): array
{
    $at = fn (string $time): string => "2026-09-06T{$time}+00:00";

    return [
        ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 2, 'position' => 2, 'date' => $at('13:01:00')],
        // Същият миг, но при по-бързия пилот това е ВТОРАТА му обиколка.
        ['overtaking_driver_number' => 3, 'overtaken_driver_number' => 1, 'position' => 1, 'date' => $at('13:01:00')],
        ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 2, 'position' => 2, 'date' => $at('13:02:00')],
        // Пилот без обиколки, време извън всички прозорци и ред без дата.
        ['overtaking_driver_number' => 7, 'overtaken_driver_number' => 1, 'position' => 5, 'date' => $at('13:01:00')],
        ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 3, 'position' => 1, 'date' => $at('14:00:00')],
        ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 3, 'position' => 1],
    ];
}

/** @param  array<string, array<int, array<string, mixed>>>  $overrides */
function seriesBundle(array $overrides = []): RaceDataBundle
{
    $rows = array_merge([
        'drivers' => seriesDrivers(),
        // Редът на финиширане е 3, 1, 2 — нарочно различен от номерата.
        'result' => [
            ['driver_number' => 3, 'position' => 1],
            ['driver_number' => 1, 'position' => 2],
            ['driver_number' => 2, 'position' => 3],
        ],
        'laps' => seriesLaps(),
        'overtakes' => seriesOvertakes(),
    ], $overrides);

    return new RaceDataBundle(
        drivers: collect($rows['drivers']),
        result: collect($rows['result']),
        laps: collect($rows['laps']),
        stints: collect($rows['stints'] ?? []),
        pits: collect($rows['pits'] ?? []),
        raceControl: collect($rows['raceControl'] ?? []),
        weather: collect($rows['weather'] ?? []),
        grid: collect($rows['grid'] ?? []),
        overtakes: collect($rows['overtakes']),
        championshipDrivers: collect($rows['championshipDrivers'] ?? []),
        championshipTeams: collect($rows['championshipTeams'] ?? []),
        intervals: collect($rows['intervals'] ?? []),
        teamRadio: collect($rows['teamRadio'] ?? []),
    );
}

/** @return array<string, mixed> */
function seriesCharts(RaceDataBundle $bundle): array
{
    return app(RaceChartsBuilder::class)->build(Race::factory()->create(), $bundle);
}

it('дава времената по обиколка на всеки пилот в реда на финиширане', function () {
    $laps = seriesCharts(seriesBundle())['laps'];

    expect($laps)->toHaveCount(3)
        ->and(array_column($laps, 'number'))->toBe([3, 1, 2])
        ->and($laps[0])->toBe([
            'number' => 3,
            'name' => 'Driver 3',
            'short' => 'D03',
            'colour' => '#FF8000',
            'points' => [[1, 40.0], [2, 40.0], [3, 40.5]],
        ]);
});

it('закръгля времената до хилядни и пропуска обиколките без време', function () {
    $laps = collect(seriesCharts(seriesBundle())['laps']);

    expect($laps->firstWhere('number', 1)['points'][0])->toBe([1, 80.123])
        // Пилот 2 губи третата си обиколка — остават две точки.
        ->and($laps->firstWhere('number', 2)['points'])->toBe([[1, 90.0], [2, 91.0]])
        // Пилот 9 няма нито едно свое време и изобщо не влиза в серията.
        ->and($laps->firstWhere('number', 9))->toBeNull();
});

it('пази мръсните обиколки в серията', function () {
    $laps = collect(seriesCharts(seriesBundle())['laps']);

    // Втората обиколка на пилот 1 е след пит. `cleanLaps()` я маха, тази серия
    // не бива — клиентът сам решава какво да покаже.
    expect($laps->firstWhere('number', 1)['points'])->toHaveCount(3)
        ->and($laps->firstWhere('number', 1)['points'][1])->toBe([2, 82.0]);
});

it('изнася корекцията за гориво като число', function () {
    expect(seriesCharts(seriesBundle())['fuel_correction'])->toBe(0.06);
});

it('превежда смените на позиции през обиколките на самия изпреварващ', function () {
    // Двете смени в 13:01:00 падат в РАЗЛИЧНИ обиколки, защото пилотите карат
    // с различно темпо. Плюс една в 13:02:00 при пилот 1.
    expect(seriesCharts(seriesBundle())['overtakes'])->toBe([[1, 1], [2, 2]]);
});

it('пропуска смяна без съвпадащ прозорец', function () {
    $charts = seriesCharts(seriesBundle([
        'overtakes' => [
            // Непознат пилот, време след края и ред без дата — нито един не
            // бива да добави обиколка.
            ['overtaking_driver_number' => 7, 'overtaken_driver_number' => 1, 'date' => '2026-09-06T13:01:00+00:00'],
            ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 3, 'date' => '2026-09-06T14:00:00+00:00'],
            ['overtaking_driver_number' => 1, 'overtaken_driver_number' => 3],
        ],
    ]));

    expect($charts)->not->toHaveKey('overtakes');
});

it('пропуска ключовете, за които няма данни', function () {
    $charts = seriesCharts(seriesBundle([
        'drivers' => [],
        'result' => [],
        'laps' => [],
        'overtakes' => [],
    ]));

    expect($charts)->not->toHaveKey('laps')
        ->and($charts)->not->toHaveKey('overtakes')
        ->and($charts)->not->toHaveKey('positions')
        // Корекцията за гориво е константа и остава дори при празен набор.
        ->and($charts['fuel_correction'])->toBe(0.06)
        ->and($charts['total_laps'])->toBe(0);
});

it('смята кумулативните времена с медиана при липсваща стойност', function () {
    $cumulative = seriesBundle()->cumulativeTimes();

    expect($cumulative[3])->toBe([1 => 40.0, 2 => 80.0, 3 => 120.5])
        // Пилот 2 няма време за обиколка 3 — влиза медианата 79,0.
        ->and($cumulative[2])->toBe([1 => 90.0, 2 => 181.0, 3 => 260.0])
        // Пилот 9 върви само на медиани и спира на обиколка 9, за която няма
        // нито своя стойност, нито медиана.
        ->and($cumulative[9])->toBe([1 => 80.123, 2 => 162.123]);
});

it('смята кумулативните времена само веднъж', function () {
    $bundle = seriesBundle();
    $first = $bundle->cumulativeTimes();

    // Ако смятането се повтореше, новата обиколка щеше да се появи в отговора.
    $bundle->laps->push(['driver_number' => 3, 'lap_number' => 4, 'lap_duration' => 41.0, 'date_start' => '2026-09-06T13:02:00+00:00']);

    expect($bundle->cumulativeTimes())->toBe($first)
        ->and($bundle->cumulativeTimes()[3])->not->toHaveKey(4);
});

it('прорежда очертанието на пистата, но пази краищата', function () {
    Http::fake(['*multiviewer*' => Http::response([
        'x' => array_map(fn (int $i): float => (float) $i, range(0, 999)),
        'y' => array_map(fn (int $i): float => (float) ($i * 2), range(0, 999)),
        'rotation' => 95,
        'corners' => [],
    ])]);

    $outline = app(CircuitGeometry::class)->forCircuit(39, 2026)['outline'];

    expect($outline)->toHaveCount(240)
        ->and($outline[0])->toBe([0.0, 0.0])
        ->and($outline[239])->toBe([999.0, 1998.0]);
});

it('не пипа очертание, което вече е достатъчно късо', function () {
    Http::fake(['*multiviewer*' => Http::response([
        'x' => array_map(fn (int $i): float => (float) $i, range(0, 99)),
        'y' => array_map(fn (int $i): float => (float) $i, range(0, 99)),
        'rotation' => 0,
        'corners' => [],
    ])]);

    expect(app(CircuitGeometry::class)->forCircuit(40, 2026)['outline'])->toHaveCount(100);
});
