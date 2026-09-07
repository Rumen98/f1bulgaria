<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;
use Illuminate\Support\Collection;

/**
 * Превръща отговора на OpenF1 в готови за рисуване серии.
 *
 * Всичко се смята ТУК, веднъж, и се записва в базата. Vue компонентите после
 * само рисуват — не филтрират, не сумират и не гадаят. Причината е двойна:
 * страницата се рендира и на сървъра (SSR), а графиките трябва да работят и
 * когато OpenF1 е недостъпен.
 *
 * Позицията по обиколка и изоставането се извеждат от КУМУЛАТИВНОТО време на
 * обиколките, а не от endpoint-а `position`. Той пази записи с времеви щампи,
 * не с номера на обиколки, така че щеше да иска мачване по дата — а „date_start“
 * на обиколка е обявено за приблизително в самата документация. Сумата от
 * времената дава и двете серии наведнъж и е съгласувана със себе си.
 */
class RaceChartsBuilder
{
    /** Колко пилота показваме в графиките, които не понасят двадесет линии. */
    private const TOP_N = 10;

    /**
     * Колко секунди на обиколка „печели“ болидът само защото олеква.
     *
     * Болидът тръгва с ~110 кг гориво и харчи 1,5-1,8 кг на обиколка, а всеки
     * килограм струва около 0,035 s. Тоест последната обиколка е с ~3 секунди
     * по-лека от първата САМО заради това.
     *
     * Без тази корекция графиката за деградация лъже в обратната посока: късен
     * стинт изглежда по-бърз от ранния, все едно гумите са се подобрили.
     * Стойността е приблизителна и еднаква за всички — целта е да се сравняват
     * състави помежду им, не да се мери абсолютно време.
     */
    private const FUEL_SECONDS_PER_LAP = 0.06;

    public function __construct(private readonly DriverResolver $drivers) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Race $race, RaceDataBundle $bundle): array
    {
        $people = $this->drivers->map($race, $bundle->drivers);
        $order = $this->finishingOrder($bundle);
        $cumulative = $this->cumulativeTimes($bundle);

        return array_filter([
            'total_laps' => $bundle->totalLaps(),
            'neutralisations' => $bundle->neutralisationWindows(),
            'positions' => $this->positionsPerLap($cumulative, $people, $order),
            'trace' => $this->traceToLeader($cumulative, $people, $order),
            'stints' => $this->stints($bundle, $people, $order),
            'grid_vs_finish' => $this->gridVsFinish($bundle, $people),
            'championship' => $this->championship($bundle, $people),
            'championship_teams' => $this->championshipTeams($bundle),
            'weather' => $this->weather($bundle),
            'pace' => $this->pace($bundle, $people, $order),
            'tyre_degradation' => $this->tyreDegradation($bundle),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Номерата на пилотите в реда на финиширане — подредбата на всяка легенда
     * в страницата, за да не скача от графика на графика.
     *
     * @return array<int, int>
     */
    private function finishingOrder(RaceDataBundle $bundle): array
    {
        return $bundle->result
            ->filter(fn (array $r) => (int) ($r['position'] ?? 0) > 0)
            ->sortBy(fn (array $r) => (int) $r['position'])
            ->map(fn (array $r) => (int) $r['driver_number'])
            ->values()
            ->all();
    }

    /**
     * Кумулативно време на всеки пилот в края на всяка обиколка.
     *
     * Липсващо време (случва се на първата обиколка и при рестарт) се замества
     * с МЕДИАНАТА на същата обиколка при останалите пилоти. Алтернативата е да
     * изхвърлим целия пилот от графиката заради една дупка — по-лошо е.
     *
     * @return array<int, array<int, float>> [номер на пилот => [обиколка => секунди]]
     */
    private function cumulativeTimes(RaceDataBundle $bundle): array
    {
        $medians = $this->medianLapTimes($bundle);
        $byDriver = $bundle->laps->groupBy(fn (array $l) => (int) $l['driver_number']);

        $out = [];

        foreach ($byDriver as $number => $laps) {
            $sorted = $laps->sortBy(fn (array $l) => (int) $l['lap_number'])->values();
            $running = 0.0;
            $series = [];

            foreach ($sorted as $lap) {
                $lapNumber = (int) $lap['lap_number'];
                $duration = is_numeric($lap['lap_duration'] ?? null)
                    ? (float) $lap['lap_duration']
                    : ($medians[$lapNumber] ?? null);

                if ($duration === null) {
                    // Нито своя стойност, нито медиана — от тук нататък
                    // сумата би била измислена, затова спираме пилота.
                    break;
                }

                $running += $duration;
                $series[$lapNumber] = round($running, 3);
            }

            if ($series !== []) {
                $out[(int) $number] = $series;
            }
        }

        return $out;
    }

    /**
     * @return array<int, float> [обиколка => медиана в секунди]
     */
    private function medianLapTimes(RaceDataBundle $bundle): array
    {
        return $bundle->laps
            ->filter(fn (array $l) => is_numeric($l['lap_duration'] ?? null))
            ->groupBy(fn (array $l) => (int) $l['lap_number'])
            ->map(function (Collection $laps): float {
                $values = $laps->map(fn (array $l) => (float) $l['lap_duration'])->sort()->values();

                return (float) $values->get((int) floor($values->count() / 2));
            })
            ->all();
    }

    /**
     * Класическата „спагети“ графика: позиция на всеки пилот след всяка обиколка.
     *
     * @param  array<int, array<int, float>>  $cumulative
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<int, int>  $order
     * @return array<int, array<string, mixed>>
     */
    private function positionsPerLap(array $cumulative, array $people, array $order): array
    {
        if ($cumulative === []) {
            return [];
        }

        $lapNumbers = $this->allLapNumbers($cumulative);
        $series = [];

        foreach ($lapNumbers as $lap) {
            $atLap = [];

            foreach ($cumulative as $number => $times) {
                if (isset($times[$lap])) {
                    $atLap[$number] = $times[$lap];
                }
            }

            asort($atLap);
            $position = 1;

            foreach (array_keys($atLap) as $number) {
                $series[$number][$lap] = $position++;
            }
        }

        return $this->shape($series, $people, $order, includeAll: true);
    }

    /**
     * Изоставането от лидера на всяка обиколка — оттам се вижда кой е бил
     * реално близо и кога питстопът е обърнал състезанието.
     *
     * @param  array<int, array<int, float>>  $cumulative
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<int, int>  $order
     * @return array<int, array<string, mixed>>
     */
    private function traceToLeader(array $cumulative, array $people, array $order): array
    {
        if ($cumulative === []) {
            return [];
        }

        $series = [];

        foreach ($this->allLapNumbers($cumulative) as $lap) {
            $atLap = [];

            foreach ($cumulative as $number => $times) {
                if (isset($times[$lap])) {
                    $atLap[$number] = $times[$lap];
                }
            }

            if ($atLap === []) {
                continue;
            }

            $leader = min($atLap);

            foreach ($atLap as $number => $time) {
                $series[$number][$lap] = round($time - $leader, 2);
            }
        }

        // Само челото: двадесет линии на един график са петно, а разликите
        // към опашката са в порядък, който смачква интересната част.
        return $this->shape($series, $people, array_slice($order, 0, self::TOP_N), includeAll: false);
    }

    /**
     * @param  array<int, array<int, float>>  $cumulative
     * @return array<int, int>
     */
    private function allLapNumbers(array $cumulative): array
    {
        $laps = [];

        foreach ($cumulative as $times) {
            foreach (array_keys($times) as $lap) {
                $laps[$lap] = true;
            }
        }

        $numbers = array_keys($laps);
        sort($numbers);

        return $numbers;
    }

    /**
     * Общата форма на серия за фронтенда: подредени пилоти с име, цвят и
     * точките като [обиколка, стойност].
     *
     * @param  array<int, array<int, int|float>>  $series
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<int, int>  $order
     * @return array<int, array<string, mixed>>
     */
    private function shape(array $series, array $people, array $order, bool $includeAll): array
    {
        $numbers = $includeAll
            ? array_values(array_unique([...$order, ...array_keys($series)]))
            : $order;

        $out = [];
        $seenTeams = [];

        foreach ($numbers as $number) {
            if (! isset($series[$number])) {
                continue;
            }

            $points = [];

            foreach ($series[$number] as $lap => $value) {
                $points[] = [$lap, $value];
            }

            // Съотборниците делят цвят — вторият от отбора върви с пунктир.
            // Без това половината линии на графиката са неразличими.
            $team = (string) ($people[$number]['team'] ?? "#{$number}");
            $dashed = isset($seenTeams[$team]);
            $seenTeams[$team] = true;

            $out[] = [
                'number' => $number,
                'name' => $people[$number]['name'] ?? ('#'.$number),
                'short' => $people[$number]['short'] ?? ('#'.$number),
                'colour' => $people[$number]['colour'] ?? '#83838d',
                'dashed' => $dashed,
                'points' => $points,
            ];
        }

        return $out;
    }

    /**
     * Стратегията по гуми: една лента на пилот, разделена на стинтове.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<int, int>  $order
     * @return array<int, array<string, mixed>>
     */
    private function stints(RaceDataBundle $bundle, array $people, array $order): array
    {
        if ($bundle->stints->isEmpty()) {
            return [];
        }

        $pitsByDriver = $bundle->pits->groupBy(fn (array $p) => (int) $p['driver_number']);
        $byDriver = $bundle->stints->groupBy(fn (array $s) => (int) $s['driver_number']);
        $out = [];

        foreach ($order as $number) {
            $stints = $byDriver->get($number);

            if ($stints === null) {
                continue;
            }

            $segments = $stints
                ->filter(fn (array $s) => (int) ($s['lap_end'] ?? 0) >= (int) ($s['lap_start'] ?? 0))
                ->sortBy(fn (array $s) => (int) $s['lap_start'])
                ->map(fn (array $s) => [
                    'compound' => (string) ($s['compound'] ?? ''),
                    'from' => (int) $s['lap_start'],
                    'to' => (int) $s['lap_end'],
                    'age' => isset($s['tyre_age_at_start']) ? (int) $s['tyre_age_at_start'] : null,
                ])
                ->values()
                ->all();

            if ($segments === []) {
                continue;
            }

            $out[] = [
                'number' => $number,
                'name' => $people[$number]['name'] ?? ('#'.$number),
                'short' => $people[$number]['short'] ?? ('#'.$number),
                'colour' => $people[$number]['colour'] ?? '#83838d',
                'segments' => $segments,
                'pits' => $pitsByDriver->get($number, collect())
                    ->map(fn (array $p) => (int) $p['lap_number'])
                    ->sort()
                    ->values()
                    ->all(),
            ];
        }

        return $out;
    }

    /**
     * Решетка срещу финал — стрелка нагоре или надолу за всеки пилот.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<int, array<string, mixed>>
     */
    private function gridVsFinish(RaceDataBundle $bundle, array $people): array
    {
        if ($bundle->grid->isEmpty()) {
            return [];
        }

        $grid = $bundle->grid->mapWithKeys(
            fn (array $g) => [(int) $g['driver_number'] => (int) ($g['position'] ?? 0)],
        );

        return $bundle->result
            ->filter(fn (array $r) => (int) ($r['position'] ?? 0) > 0)
            ->sortBy(fn (array $r) => (int) $r['position'])
            ->map(function (array $r) use ($grid, $people): ?array {
                $number = (int) $r['driver_number'];
                $from = $grid->get($number);

                if ($from === null || $from === 0) {
                    return null;
                }

                return [
                    'number' => $number,
                    'name' => $people[$number]['name'] ?? ('#'.$number),
                    'short' => $people[$number]['short'] ?? ('#'.$number),
                    'colour' => $people[$number]['colour'] ?? '#83838d',
                    'from' => $from,
                    'to' => (int) $r['position'],
                    'dnf' => ($r['dnf'] ?? false) === true,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Шампионатът преди и след кръга — първите осем.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<int, array<string, mixed>>
     */
    private function championship(RaceDataBundle $bundle, array $people): array
    {
        return $bundle->championshipDrivers
            ->filter(fn (array $r) => isset($r['points_current'], $r['position_current']))
            ->sortBy(fn (array $r) => (int) $r['position_current'])
            ->take(8)
            ->map(function (array $r) use ($people): array {
                $number = (int) $r['driver_number'];

                return [
                    'number' => $number,
                    'name' => $people[$number]['name'] ?? ('#'.$number),
                    'short' => $people[$number]['short'] ?? ('#'.$number),
                    'colour' => $people[$number]['colour'] ?? '#83838d',
                    'before' => (int) ($r['points_start'] ?? 0),
                    'after' => (int) $r['points_current'],
                    'position' => (int) $r['position_current'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Деградация на гумите: колко се влошава времето с възрастта на гумата.
     *
     * Оста X е ВЪЗРАСТТА на гумата в обиколки, не номерът на обиколката — така
     * стинтовете от различни моменти на състезанието лягат един върху друг и
     * съставите стават сравними.
     *
     * Времената са коригирани за гориво (виж FUEL_SECONDS_PER_LAP). Без това
     * кривата тръгва надолу и излиза, че гумата се подобрява с износването.
     *
     * Точките са суровите обиколки, линията е медианата по възраст. Медиана,
     * защото един задръстен зад по-бавен болид пилот дърпа средното с секунди.
     *
     * @return array<string, mixed>
     */
    private function tyreDegradation(RaceDataBundle $bundle): array
    {
        if ($bundle->stints->isEmpty()) {
            return [];
        }

        $totalLaps = $bundle->totalLaps();
        $stints = $bundle->stints->groupBy(fn (array $s) => (int) $s['driver_number']);
        $byCompound = [];

        foreach ($bundle->cleanLaps() as $lap) {
            $driver = (int) $lap['driver_number'];
            $number = (int) $lap['lap_number'];
            $stint = $stints->get($driver, collect())->first(
                fn (array $s) => $number >= (int) ($s['lap_start'] ?? 0) && $number <= (int) ($s['lap_end'] ?? 0),
            );

            if ($stint === null || blank($stint['compound'] ?? null)) {
                continue;
            }

            $age = (int) ($stint['tyre_age_at_start'] ?? 0) + ($number - (int) $stint['lap_start']);
            $corrected = (float) $lap['lap_duration'] - ($totalLaps - $number) * self::FUEL_SECONDS_PER_LAP;

            $byCompound[mb_strtoupper((string) $stint['compound'])][] = [$age, round($corrected, 2)];
        }

        $out = [];

        foreach ($byCompound as $compound => $points) {
            // Под десет обиколки няма крива, има шум.
            if (count($points) < 10) {
                continue;
            }

            $out[] = [
                'compound' => $compound,
                'points' => $points,
                'median' => $this->medianByAge($points),
            ];
        }

        return $out === [] ? [] : ['compounds' => $out];
    }

    /**
     * @param  array<int, array{0:int, 1:float}>  $points
     * @return array<int, array{0:int, 1:float}>
     */
    private function medianByAge(array $points): array
    {
        $grouped = [];

        foreach ($points as [$age, $time]) {
            $grouped[$age][] = $time;
        }

        ksort($grouped);
        $out = [];

        foreach ($grouped as $age => $times) {
            // Възраст с една-две обиколки не носи медиана, а случайност.
            if (count($times) < 3) {
                continue;
            }

            sort($times);
            $out[] = [$age, round($times[(int) floor(count($times) / 2)], 2)];
        }

        return $out;
    }

    /**
     * Шампионатът при конструкторите. Цветът идва от нашата таблица, ако
     * отборът се разпознае по име — OpenF1 не връща цвят на отбор тук.
     *
     * @return array<int, array<string, mixed>>
     */
    private function championshipTeams(RaceDataBundle $bundle): array
    {
        $colours = $bundle->drivers
            ->filter(fn (array $d) => filled($d['team_name'] ?? null) && filled($d['team_colour'] ?? null))
            ->mapWithKeys(fn (array $d) => [
                (string) $d['team_name'] => '#'.ltrim((string) $d['team_colour'], '#'),
            ]);

        return $bundle->championshipTeams
            ->filter(fn (array $r) => isset($r['points_current'], $r['position_current'], $r['team_name']))
            ->sortBy(fn (array $r) => (int) $r['position_current'])
            ->map(fn (array $r) => [
                'number' => (int) $r['position_current'],
                'name' => (string) $r['team_name'],
                'short' => (string) $r['team_name'],
                'colour' => $colours->get((string) $r['team_name'], '#83838d'),
                'before' => (int) ($r['points_start'] ?? 0),
                'after' => (int) $r['points_current'],
                'position' => (int) $r['position_current'],
            ])
            ->values()
            ->all();
    }

    /**
     * Температурата на асфалта през сесията — покрива и часа преди старта, така
     * че се вижда как пистата се загрява и охлажда.
     *
     * @return array<string, mixed>|null
     */
    private function weather(RaceDataBundle $bundle): ?array
    {
        $rows = $bundle->weather
            ->filter(fn (array $w) => isset($w['date']) && is_numeric($w['track_temperature'] ?? null))
            ->sortBy(fn (array $w) => (string) $w['date'])
            ->values();

        if ($rows->count() < 5) {
            return null;
        }

        $start = strtotime((string) $rows->first()['date']);

        if ($start === false) {
            return null;
        }

        return [
            'points' => $rows->map(function (array $w) use ($start): array {
                $at = strtotime((string) $w['date']);

                return [
                    // Минути от началото на записа — фронтендът не бива да
                    // прави часови сметки.
                    $at === false ? 0 : (int) round(($at - $start) / 60),
                    round((float) $w['track_temperature'], 1),
                    is_numeric($w['air_temperature'] ?? null) ? round((float) $w['air_temperature'], 1) : null,
                    (int) ($w['rainfall'] ?? 0) > 0 ? 1 : 0,
                ];
            })->all(),
        ];
    }

    /**
     * Реалното темпо: медианата на чистите обиколки на всеки пилот, като
     * изоставане спрямо най-бързия.
     *
     * Медиана, а не средно: едно засядане зад по-бавен болид дърпа средното
     * повече, отколкото описва темпото.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<int, int>  $order
     * @return array<int, array<string, mixed>>
     */
    private function pace(RaceDataBundle $bundle, array $people, array $order): array
    {
        $clean = $bundle->cleanLaps();

        if ($clean->isEmpty()) {
            return [];
        }

        $medians = $clean
            ->groupBy(fn (array $l) => (int) $l['driver_number'])
            // Под пет чисти обиколки медианата не описва нищо.
            ->filter(fn (Collection $laps) => $laps->count() >= 5)
            ->map(function (Collection $laps): float {
                $values = $laps->map(fn (array $l) => (float) $l['lap_duration'])->sort()->values();

                return round((float) $values->get((int) floor($values->count() / 2)), 3);
            });

        if ($medians->isEmpty()) {
            return [];
        }

        $best = (float) $medians->min();

        return collect($order)
            ->filter(fn (int $number) => $medians->has($number))
            ->map(fn (int $number) => [
                'number' => $number,
                'name' => $people[$number]['name'] ?? ('#'.$number),
                'short' => $people[$number]['short'] ?? ('#'.$number),
                'colour' => $people[$number]['colour'] ?? '#83838d',
                'median' => $medians->get($number),
                'delta' => round($medians->get($number) - $best, 3),
            ])
            ->values()
            ->all();
    }
}
