<?php

declare(strict_types=1);

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Race;
use App\Models\Season;
use App\Services\News\Llm\LlmClient;
use App\Services\RaceData\RaceDataBundle;
use App\Services\RaceData\RaceDataFetcher;
use App\Services\RaceData\RaceFactsBuilder;
use App\Services\RaceData\RaceMomentsBuilder;
use App\Services\RaceData\RaceRecapGenerator;
use App\Services\RaceData\RaceSessionKeys;
use App\Services\RaceData\RaceSessionResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

/** Сезон, отбор и 14 пилота с български имена — резолверът ги търси по номер. */
function momentsRace(): Race
{
    $season = Season::factory()->create(['year' => 2026, 'is_current' => true]);
    $constructor = Constructor::factory()->create(['season_id' => $season->id, 'color_hex' => '#FF8000']);

    foreach (range(1, 14) as $n) {
        Driver::factory()->create([
            'season_id' => $season->id,
            'constructor_id' => $constructor->id,
            'permanent_number' => $n,
            'driver_code' => 'M'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'slug' => 'moments-driver-'.$n,
            'first_name' => 'Пилот',
            'last_name' => (string) $n,
        ]);
    }

    return Race::factory()->create([
        'season_id' => $season->id,
        'round' => 15,
        'name' => 'Singapore Grand Prix',
        'jolpica_id' => 'marina_bay',
        'race_datetime_utc' => now()->subHours(5),
    ]);
}

/** @return array<int, array<string, mixed>> */
function momentsDrivers(): array
{
    return array_map(fn (int $n) => [
        'driver_number' => $n,
        'name_acronym' => 'M'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'full_name' => "Driver {$n}",
        'team_name' => 'Test Racing',
        'team_colour' => 'FF8000',
    ], range(1, 14));
}

/**
 * @param  array<int, array<string, mixed>>  $laps
 * @param  array<int, array<string, mixed>>  $result
 * @param  array<int, array<string, mixed>>  $pits
 * @param  array<int, array<string, mixed>>  $raceControl
 * @param  array<int, array<string, mixed>>  $grid
 */
function momentsBundleOf(array $laps, array $result, array $pits = [], array $raceControl = [], array $grid = []): RaceDataBundle
{
    return new RaceDataBundle(
        drivers: collect(momentsDrivers()),
        result: collect($result),
        laps: collect($laps),
        stints: collect(),
        pits: collect($pits),
        raceControl: collect($raceControl),
        weather: collect(),
        grid: collect($grid),
        overtakes: collect(),
        championshipDrivers: collect(),
        championshipTeams: collect(),
        intervals: collect(),
        teamRadio: collect(),
    );
}

/** Реда на финиширане: номерът на пилота е и позицията му. */
function momentsResult(): array
{
    return array_map(fn (int $n) => [
        'driver_number' => $n,
        'position' => $n,
        'number_of_laps' => 24,
        'dnf' => false,
        'dns' => false,
        'dsq' => false,
    ], range(1, 14));
}

/**
 * @param  callable(int, int): float  $duration
 * @return array<int, array<string, mixed>>
 */
function momentsLapRows(callable $duration, int $totalLaps): array
{
    $rows = [];

    foreach (range(1, 14) as $n) {
        foreach (range(1, $totalLaps) as $lap) {
            $rows[] = [
                'driver_number' => $n,
                'lap_number' => $lap,
                'lap_duration' => $duration($n, $lap),
                'st_speed' => momentsSpeed($n, $lap),
                'is_pit_out_lap' => $lap === 14 && $n <= 2,
                'date_start' => now()->subHours(5)->addSeconds($lap * 90)->toIso8601String(),
            ];
        }
    }

    return $rows;
}

function momentsSpeed(int $driver, int $lap): int
{
    return match (true) {
        $driver === 1 => 330,
        // Най-високата стойност пада в обиколка под неутрализация — засичането
        // е на правата и си остава валидно.
        $driver === 2 => $lap === 10 ? 340 : 320,
        // 3 км/ч е счупен запис, не рекорд.
        $driver === 3 => 3,
        default => 300,
    };
}

/**
 * Уикендът, върху който стъпват повечето проверки: първите двама се разделят
 * по темпо (2–8), събират се под safety car (9–11) и спират заедно (13).
 */
function momentsWeekend(): RaceDataBundle
{
    $duration = function (int $driver, int $lap): float {
        if ($driver === 1) {
            return match ($lap) {
                13 => 112.0,
                14 => 95.0,
                default => 90.0,
            };
        }

        if ($driver === 2) {
            return match (true) {
                $lap <= 8 => 90.5,
                $lap <= 11 => 89.0,
                $lap === 13 => 118.0,
                $lap === 14 => 95.0,
                default => 90.0,
            };
        }

        return 91.0 + ($driver - 3) * 0.1;
    };

    return momentsBundleOf(
        laps: momentsLapRows($duration, 24),
        result: momentsResult(),
        pits: [
            ['driver_number' => 1, 'lap_number' => 13, 'lane_duration' => 21.0],
            ['driver_number' => 2, 'lap_number' => 13, 'lane_duration' => 22.0],
        ],
        raceControl: [
            ['date' => '2026-09-06T13:20:00+00:00', 'category' => 'SafetyCar', 'message' => 'SAFETY CAR DEPLOYED', 'lap_number' => 9],
            ['date' => '2026-09-06T13:26:00+00:00', 'category' => 'Flag', 'flag' => 'GREEN', 'message' => 'SAFETY CAR IN THIS LAP', 'lap_number' => 11],
        ],
        grid: [
            ['driver_number' => 1, 'position' => 1],
            ['driver_number' => 3, 'position' => 2],
            ['driver_number' => 2, 'position' => 3],
        ],
    );
}

/**
 * Пет отделни сегмента с нарастваща сила и плоски обиколки между тях — за
 * проверка на подбора и на тавана от шест момента.
 */
function momentsAlternating(): RaceDataBundle
{
    $duration = function (int $driver, int $lap): float {
        if ($driver === 1) {
            return 90.0;
        }

        if ($driver === 2) {
            return match ($lap) {
                2 => 91.0,
                4 => 88.0,
                6 => 93.0,
                8 => 86.0,
                10 => 95.0,
                default => 90.0,
            };
        }

        return 91.0;
    };

    return momentsBundleOf(momentsLapRows($duration, 12), momentsResult());
}

it('сегментира разликата между първите двама по знака на промяната', function () {
    $moments = app(RaceMomentsBuilder::class)->build(momentsRace(), momentsWeekend());

    expect($moments)->toHaveCount(5)
        ->and(array_column($moments, 'type'))->toBe(['start', 'pace', 'neutralisation', 'pit', 'finish'])
        ->and(array_column($moments, 'from'))->toBe([1, 2, 9, 13, 24])
        ->and(array_column($moments, 'to'))->toBe([1, 8, 11, 13, 24])
        // Сборът на промяната по сегменти: +0,5 × 7, −1,0 × 3 и един питстоп.
        ->and(array_column($moments, 'delta'))->toBe([null, 3.5, -3.0, 6.0, null])
        ->and($moments[1]['drivers'])->toBe([1, 2]);
});

it('изписва петте типа момент по шаблон', function () {
    $moments = app(RaceMomentsBuilder::class)->build(momentsRace(), momentsWeekend());

    expect($moments[0]['label'])->toBe('Старт')
        ->and($moments[0]['detail'])->toBe('Пилот 1 тръгва 1-и, Пилот 2 — 3-и.')
        ->and($moments[1]['label'])->toBe('Обиколки 2–8')
        ->and($moments[1]['detail'])->toBe('Пилот 1 се откъсва с 0,50 с на обиколка, общо 3,5 с за 7 обиколки.')
        ->and($moments[2]['label'])->toBe('Обиколки 9–11')
        ->and($moments[2]['detail'])->toBe('Неутрализация на трасето. Разликата между първите двама намалява с 3,0 с.')
        ->and($moments[3]['label'])->toBe('Обиколка 13')
        ->and($moments[3]['detail'])->toBe('Питстопове: Пилот 1 — обиколка 13, Пилот 2 — обиколка 13. Разликата между първите двама расте с 6,0 с.')
        ->and($moments[4]['label'])->toBe('Финал')
        ->and($moments[4]['detail'])->toBe('Пилот 1 завършва пред Пилот 2 с 7,0 с.');
});

it('взима най-силните сегменти и спира на шест момента', function () {
    $moments = app(RaceMomentsBuilder::class)->build(momentsRace(), momentsAlternating());

    // Сегментите са пет: ±1, ±2, ±3, ±4 и ±5 секунди. Най-слабият (обиколка 2)
    // отпада, за да останат четири междинни момента.
    expect($moments)->toHaveCount(6)
        ->and(array_column($moments, 'from'))->toBe([1, 4, 6, 8, 10, 12])
        ->and(array_column($moments, 'delta'))->toBe([null, -2.0, 3.0, -4.0, 5.0, null])
        ->and(array_column($moments, 'type'))->toBe(['start', 'pace', 'pace', 'pace', 'pace', 'finish'])
        // Без стартова решетка стартът пада към разликата след първата обиколка.
        ->and($moments[0]['detail'])->toBe('След обиколка 1 разликата е 0,0 с.')
        ->and($moments[1]['detail'])->toBe('Пилот 2 наваксва с 2,0 с.');
});

it('мълчи, ако вторият от реда на финиширане липсва', function () {
    $bundle = momentsBundleOf(
        laps: momentsLapRows(fn (int $driver, int $lap): float => 90.0, 24),
        result: [['driver_number' => 1, 'position' => 1]],
    );

    expect(app(RaceMomentsBuilder::class)->build(momentsRace(), $bundle))->toBe([]);
});

it('мълчи, ако няма кумулативни времена', function () {
    $bundle = momentsBundleOf(laps: [], result: momentsResult());

    expect(app(RaceMomentsBuilder::class)->build(momentsRace(), $bundle))->toBe([]);
});

it('не описва причини, а само какво се е случило и с колко', function () {
    $race = momentsRace();
    $builder = app(RaceMomentsBuilder::class);
    $text = mb_strtolower(json_encode(
        [$builder->build($race, momentsWeekend()), $builder->build($race, momentsAlternating())],
        JSON_UNESCAPED_UNICODE,
    ));

    // Причинното твърдение няма как да се провери: в данните няма
    // контрафактуал. Затова забраната е тествана, а не подразбираща се.
    foreach (['спечел', 'реши', 'решав', 'обърна', 'заради', 'защото', 'причин', 'благодарение'] as $word) {
        expect($text)->not->toContain($word);
    }
});

it('вади най-бърза чиста обиколка и максимална скорост за всеки пилот', function () {
    $perDriver = app(RaceFactsBuilder::class)->perDriver(momentsWeekend());

    expect($perDriver[1]['fastest_lap'])->toBe(90.0)
        ->and($perDriver[1]['fastest_lap_display'])->toBe('1:30.000')
        ->and($perDriver[1]['fastest_lap_number'])->toBe(1)
        ->and($perDriver[1]['top_speed'])->toBe(330)
        // При втория обиколки 1–8 са с 90,5, а 9–11 падат под неутрализацията,
        // 13 и 14 са пит и излизане — остава 12 като първа чиста с 90,0.
        ->and($perDriver[2]['fastest_lap_number'])->toBe(12)
        ->and($perDriver[2]['top_speed'])->toBe(340)
        ->and($perDriver[3]['fastest_lap'])->toBe(91.0)
        ->and($perDriver[3]['top_speed'])->toBeNull()
        // Ключовете трябва да излязат като обект, не като списък — фронтендът
        // ги адресира по номер на пилот.
        ->and(json_encode($perDriver))->toStartWith('{"1":');
});

it('закача моментите в charts и числата по пилот във facts', function () {
    $race = momentsRace();
    $bundle = momentsWeekend();

    Http::fake(['*' => Http::response([])]);

    $this->mock(LlmClient::class, function ($mock) {
        $mock->shouldReceive('completeWithTool')->andThrow(new RuntimeException('без LLM в тестове'));
    });

    $this->mock(RaceSessionResolver::class, function ($mock) {
        $mock->shouldReceive('resolve')->andReturn(new RaceSessionKeys(race: 7002, qualifying: 7001, circuit: 39, year: 2026));
    });

    $this->mock(RaceDataFetcher::class, function ($mock) use ($bundle) {
        $mock->shouldReceive('fetch')->andReturn($bundle);
    });

    $generated = app(RaceRecapGenerator::class)->generate($race);

    expect($generated['error'])->toBeNull()
        ->and($generated['recap']->charts['moments'])->toHaveCount(5)
        ->and($generated['recap']->charts['moments'][0]['type'])->toBe('start')
        ->and($generated['recap']->facts['per_driver'][1]['top_speed'])->toBe(330);
});

/**
 * Уикенд с три питстопа и два по-слаби, но истински състезателни сегмента.
 * Механичните са по ~22 с, състезателните — по 2 с.
 */
function momentsPitHeavy(): RaceDataBundle
{
    $duration = function (int $driver, int $lap): float {
        if ($driver === 1) {
            return match (true) {
                $lap === 11 => 112.0,               // питстоп
                $lap >= 2 && $lap <= 5 => 89.5,     // по-бърз, +0,5 с/обиколка
                $lap >= 15 && $lap <= 20 => 90.4,   // по-бавен, −0,4 с/обиколка
                default => 90.0,
            };
        }

        if ($driver === 2) {
            return in_array($lap, [8, 22], true) ? 112.0 : 90.0;
        }

        return 90.0;
    };

    return momentsBundleOf(
        momentsLapRows($duration, 24),
        momentsResult(),
        [
            ['driver_number' => 2, 'lap_number' => 8],
            ['driver_number' => 1, 'lap_number' => 11],
            ['driver_number' => 2, 'lap_number' => 22],
        ],
    );
}

it('пази места за темпото, вместо да върне само пит цикли', function () {
    // Без квота класирането по големина би дало три механични момента и един
    // състезателен: питстопът мести разликата с 22 с, а половин секунда на
    // обиколка — с 2 с. Тогава разделът отговаря „механиката“ на въпроса къде
    // се е решавало, което е вярно и безполезно.
    $types = array_column(app(RaceMomentsBuilder::class)->build(momentsRace(), momentsPitHeavy()), 'type');

    expect($types[0])->toBe('start')
        ->and($types[count($types) - 1])->toBe('finish')
        ->and(count(array_filter($types, fn (string $t) => $t === 'pace')))->toBe(2)
        ->and(count(array_filter($types, fn (string $t) => $t === 'pit')))->toBe(2);
});

it('пълни с механични, когато състезателни сегменти няма', function () {
    // Квотата е предпочитание, не изискване — таванът остава четири.
    $duration = fn (int $driver, int $lap): float => $driver === 2 && in_array($lap, [5, 9, 13, 17, 21], true)
        ? 112.0
        : 90.0;

    $bundle = momentsBundleOf(
        momentsLapRows($duration, 24),
        momentsResult(),
        array_map(fn (int $lap) => ['driver_number' => 2, 'lap_number' => $lap], [5, 9, 13, 17, 21]),
    );

    $types = array_column(app(RaceMomentsBuilder::class)->build(momentsRace(), $bundle), 'type');

    expect(array_filter($types, fn (string $t) => $t === 'pace'))->toBe([])
        ->and(count($types))->toBe(6);
});
