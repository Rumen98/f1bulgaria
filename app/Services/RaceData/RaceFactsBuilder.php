<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;
use Illuminate\Support\Collection;

/**
 * Изважда числата, върху които стъпва целият рекап.
 *
 * Първото нещо, което прави, е да провери дали изобщо да си отвори устата:
 * ако наборът не мине санитарните граници, връща null и нищо не се публикува.
 * Публикуван пост с „3 км/ч“ е по-лош от липсващ пост — при 40 потребители
 * едно смешно число струва повече доверие, отколкото десет верни печелят.
 */
class RaceFactsBuilder
{
    /** Разумни граници за състезание от Формула 1. Извън тях данните са счупени. */
    private const MIN_DRIVERS = 12;

    private const MAX_DRIVERS = 26;

    private const MIN_TOP_SPEED = 250;

    private const MAX_TOP_SPEED = 400;

    private const MIN_LAP_SECONDS = 55.0;

    private const MAX_LAP_SECONDS = 200.0;

    private const MIN_LAPS = 20;

    private const MAX_LAPS = 90;

    /** Влизане в пит лейна над този праг е артефакт от червен флаг, не питстоп. */
    private const MAX_SANE_LANE_SECONDS = 60.0;

    public function __construct(private readonly DriverResolver $drivers) {}

    /**
     * @return array<string, mixed>|null null = данните не са годни за публикуване
     */
    public function build(Race $race, RaceDataBundle $bundle): ?array
    {
        $people = $this->drivers->map($race, $bundle->drivers);

        if (! $this->passesSanityChecks($bundle, $people)) {
            return null;
        }

        return array_filter([
            'laps' => $bundle->totalLaps(),
            'drivers' => count($people),
            'winner' => $this->winner($bundle, $people),
            'podium' => $this->podium($bundle, $people),
            'fastest_lap' => $this->fastestLap($bundle, $people),
            'top_speed' => $this->topSpeed($bundle, $people),
            'stops' => $this->stops($bundle, $people),
            'movers' => $this->movers($bundle, $people),
            'championship' => $this->championship($bundle, $people),
            'neutralisations' => $this->neutralisations($bundle),
            'weather' => $this->weather($bundle),
            'position_changes' => $bundle->overtakes->count() ?: null,
            'pit' => $this->pit($bundle, $people),
            'battle' => $this->battle($bundle, $people),
            'radio' => $this->radio($bundle),
        ], fn ($value) => $value !== null);
    }

    /**
     * Числата на всеки пилот поотделно — гръбнакът на „Двубой в кръга“.
     *
     * Стои извън `build()` нарочно: пазачът за измислени числа в RecapComposer
     * събира всяко число от фактите и го обявява за законно. Двадесет пилота по
     * четири стойности биха му отворили толкова широка врата, че почти всяко
     * число на модела би минало за вярно. Затова се закача СЛЕД разказа.
     *
     * Ключовете са цели числа в PHP, но номерата на пилотите не започват от
     * нула, така че `json_encode` ги изнася като обект със стрингови ключове —
     * точно каквото чака фронтендът.
     *
     * @return array<int, array{fastest_lap:?float, fastest_lap_display:?string, fastest_lap_number:?int, top_speed:?int}>
     */
    public function perDriver(RaceDataBundle $bundle): array
    {
        $fastest = [];

        foreach ($bundle->cleanLaps() as $lap) {
            $seconds = (float) $lap['lap_duration'];

            // Същите граници като при общата най-бърза обиколка: чиста
            // обиколка от 300 секунди е артефакт, не рекорд.
            if ($seconds < self::MIN_LAP_SECONDS || $seconds > self::MAX_LAP_SECONDS) {
                continue;
            }

            $number = (int) $lap['driver_number'];

            if (! isset($fastest[$number]) || $seconds < $fastest[$number]['seconds']) {
                $fastest[$number] = ['seconds' => $seconds, 'lap' => (int) $lap['lap_number']];
            }
        }

        // Максималната скорост се търси във ВСИЧКИ обиколки: засичането е на
        // правата и не се разваля от питстоп или неутрализация.
        $speeds = [];

        foreach ($bundle->laps as $lap) {
            $speed = $lap['st_speed'] ?? null;

            if (! is_numeric($speed) || (int) $speed < self::MIN_TOP_SPEED || (int) $speed > self::MAX_TOP_SPEED) {
                continue;
            }

            $number = (int) $lap['driver_number'];
            $speeds[$number] = max($speeds[$number] ?? 0, (int) $speed);
        }

        $out = [];

        foreach (array_unique([...array_keys($fastest), ...array_keys($speeds)]) as $number) {
            $best = $fastest[$number] ?? null;
            $seconds = $best === null ? null : round($best['seconds'], 3);

            $out[$number] = [
                'fastest_lap' => $seconds,
                'fastest_lap_display' => $seconds === null ? null : self::lapTime($seconds),
                'fastest_lap_number' => $best === null ? null : $best['lap'],
                'top_speed' => $speeds[$number] ?? null,
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     */
    private function passesSanityChecks(RaceDataBundle $bundle, array $people): bool
    {
        $drivers = count($people);

        if ($drivers < self::MIN_DRIVERS || $drivers > self::MAX_DRIVERS) {
            return false;
        }

        $laps = $bundle->totalLaps();

        if ($laps < self::MIN_LAPS || $laps > self::MAX_LAPS) {
            return false;
        }

        // Класация без победител значи, че сесията не е приключила или
        // отговорът е бил празен.
        return $bundle->result->contains(fn (array $r) => (int) ($r['position'] ?? 0) === 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function winner(RaceDataBundle $bundle, array $people): ?array
    {
        $first = $bundle->result->first(fn (array $r) => (int) ($r['position'] ?? 0) === 1);
        $second = $bundle->result->first(fn (array $r) => (int) ($r['position'] ?? 0) === 2);

        if ($first === null) {
            return null;
        }

        $person = $people[(int) $first['driver_number']] ?? null;

        if ($person === null) {
            return null;
        }

        // gap_to_leader на втория е изоставането му спрямо победителя. В
        // състезание може да е и низ („+1 LAP“) — тогава просто няма число.
        $gap = $second !== null && is_numeric($second['gap_to_leader'] ?? null)
            ? round((float) $second['gap_to_leader'], 3)
            : null;

        return [
            'name' => $person['name'],
            'team' => $person['team'],
            'colour' => $person['colour'],
            'slug' => $person['slug'],
            'gap_to_second' => $gap,
            'laps' => isset($first['number_of_laps']) ? (int) $first['number_of_laps'] : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array<int, array<string, mixed>>
     */
    private function podium(RaceDataBundle $bundle, array $people): array
    {
        return $bundle->result
            ->filter(fn (array $r) => in_array((int) ($r['position'] ?? 0), [1, 2, 3], true))
            ->sortBy(fn (array $r) => (int) $r['position'])
            ->map(fn (array $r) => [
                'position' => (int) $r['position'],
                'name' => $people[(int) $r['driver_number']]['name'] ?? ('#'.$r['driver_number']),
                'colour' => $people[(int) $r['driver_number']]['colour'] ?? '#83838d',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function fastestLap(RaceDataBundle $bundle, array $people): ?array
    {
        $best = $bundle->laps
            ->filter(fn (array $l) => is_numeric($l['lap_duration'] ?? null))
            ->filter(function (array $l): bool {
                $seconds = (float) $l['lap_duration'];

                return $seconds >= self::MIN_LAP_SECONDS && $seconds <= self::MAX_LAP_SECONDS;
            })
            ->sortBy(fn (array $l) => (float) $l['lap_duration'])
            ->first();

        if ($best === null) {
            return null;
        }

        $seconds = round((float) $best['lap_duration'], 3);

        return [
            'name' => $people[(int) $best['driver_number']]['name'] ?? ('#'.$best['driver_number']),
            'colour' => $people[(int) $best['driver_number']]['colour'] ?? '#83838d',
            'seconds' => $seconds,
            'display' => self::lapTime($seconds),
            'lap' => (int) $best['lap_number'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function topSpeed(RaceDataBundle $bundle, array $people): ?array
    {
        $best = $bundle->laps
            ->filter(function (array $l): bool {
                $speed = $l['st_speed'] ?? null;

                return is_numeric($speed)
                    && (int) $speed >= self::MIN_TOP_SPEED
                    && (int) $speed <= self::MAX_TOP_SPEED;
            })
            ->sortByDesc(fn (array $l) => (int) $l['st_speed'])
            ->first();

        if ($best === null) {
            return null;
        }

        return [
            'name' => $people[(int) $best['driver_number']]['name'] ?? ('#'.$best['driver_number']),
            'colour' => $people[(int) $best['driver_number']]['colour'] ?? '#83838d',
            'kmh' => (int) $best['st_speed'],
            'lap' => (int) $best['lap_number'],
        ];
    }

    /**
     * Стратегиите: колко спирания е направил всеки и с какви гуми е карал
     * победителят.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function stops(RaceDataBundle $bundle, array $people): ?array
    {
        if ($bundle->stints->isEmpty()) {
            return null;
        }

        // Стинт с lap_start == lap_end е артефакт (влизане и излизане в
        // същата обиколка) и не бива да брои за отделна стратегия.
        $byDriver = $bundle->stints
            ->filter(fn (array $s) => (int) ($s['lap_end'] ?? 0) > (int) ($s['lap_start'] ?? 0))
            ->groupBy(fn (array $s) => (int) $s['driver_number']);

        if ($byDriver->isEmpty()) {
            return null;
        }

        $counts = $byDriver->map(fn (Collection $stints) => max(0, $stints->count() - 1));

        $mostNumber = (int) $counts->sortDesc()->keys()->first();
        $leastNumber = (int) $counts->sort()->keys()->first();

        $winnerNumber = $this->winnerNumber($bundle);

        return [
            'most' => [
                'name' => $people[$mostNumber]['name'] ?? ('#'.$mostNumber),
                'count' => (int) $counts->get($mostNumber),
            ],
            'least' => [
                'name' => $people[$leastNumber]['name'] ?? ('#'.$leastNumber),
                'count' => (int) $counts->get($leastNumber),
            ],
            'winner_stops' => $winnerNumber !== null ? (int) ($counts->get($winnerNumber) ?? 0) : null,
            'winner_compounds' => $winnerNumber !== null
                ? $byDriver->get($winnerNumber, collect())
                    ->sortBy(fn (array $s) => (int) $s['lap_start'])
                    ->map(fn (array $s) => (string) ($s['compound'] ?? ''))
                    ->filter()
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * Кой е спечелил и кой е загубил най-много позиции спрямо решетката.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function movers(RaceDataBundle $bundle, array $people): ?array
    {
        if ($bundle->grid->isEmpty()) {
            return null;
        }

        $grid = $bundle->grid->mapWithKeys(
            fn (array $g) => [(int) $g['driver_number'] => (int) ($g['position'] ?? 0)],
        );

        $deltas = $bundle->result
            ->filter(fn (array $r) => (int) ($r['position'] ?? 0) > 0)
            // Отпадналите нямат смислена „печалба“ — 20-о място след
            // счупен двигател не е загуба на позиции в състезателен смисъл.
            ->reject(fn (array $r) => ($r['dnf'] ?? false) === true || ($r['dns'] ?? false) === true || ($r['dsq'] ?? false) === true)
            ->map(function (array $r) use ($grid, $people): ?array {
                $number = (int) $r['driver_number'];
                $from = $grid->get($number);

                if ($from === null || $from === 0) {
                    return null;
                }

                return [
                    'name' => $people[$number]['name'] ?? ('#'.$number),
                    'colour' => $people[$number]['colour'] ?? '#83838d',
                    'from' => $from,
                    'to' => (int) $r['position'],
                    'gained' => $from - (int) $r['position'],
                ];
            })
            ->filter()
            ->values();

        if ($deltas->isEmpty()) {
            return null;
        }

        $best = $deltas->sortByDesc('gained')->first();
        $worst = $deltas->sortBy('gained')->first();

        return [
            'climber' => $best['gained'] > 0 ? $best : null,
            'faller' => $worst['gained'] < 0 ? $worst : null,
        ];
    }

    /**
     * Шампионатната люлка — най-евтината силна история в целия набор: две
     * заявки дават „кой колко спечели и как се сви разликата“.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function championship(RaceDataBundle $bundle, array $people): ?array
    {
        if ($bundle->championshipDrivers->isEmpty()) {
            return null;
        }

        $rows = $bundle->championshipDrivers
            ->filter(fn (array $r) => isset($r['points_current'], $r['position_current']))
            ->sortBy(fn (array $r) => (int) $r['position_current'])
            ->values();

        $leader = $rows->first();
        $runnerUp = $rows->get(1);

        if ($leader === null) {
            return null;
        }

        $number = (int) $leader['driver_number'];
        $gapNow = $runnerUp !== null
            ? (int) $leader['points_current'] - (int) $runnerUp['points_current']
            : null;
        $gapBefore = $runnerUp !== null && isset($leader['points_start'], $runnerUp['points_start'])
            ? (int) $leader['points_start'] - (int) $runnerUp['points_start']
            : null;

        return [
            'leader' => $people[$number]['name'] ?? ('#'.$number),
            'colour' => $people[$number]['colour'] ?? '#83838d',
            'points' => (int) $leader['points_current'],
            'gap' => $gapNow,
            'gap_change' => $gapNow !== null && $gapBefore !== null ? $gapNow - $gapBefore : null,
            'changed_leader' => isset($leader['position_start']) && (int) $leader['position_start'] !== 1,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function neutralisations(RaceDataBundle $bundle): ?array
    {
        $windows = $bundle->neutralisationWindows();

        if ($windows === []) {
            return null;
        }

        return [
            'count' => count($windows),
            'laps' => array_sum(array_map(fn (array $w) => $w['to'] - $w['from'] + 1, $windows)),
            'windows' => $windows,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weather(RaceDataBundle $bundle): ?array
    {
        $track = $bundle->weather->pluck('track_temperature')->filter(fn ($v) => is_numeric($v));
        $air = $bundle->weather->pluck('air_temperature')->filter(fn ($v) => is_numeric($v));

        if ($track->isEmpty()) {
            return null;
        }

        return [
            'track_min' => round((float) $track->min(), 1),
            'track_max' => round((float) $track->max(), 1),
            'air_avg' => $air->isEmpty() ? null : round((float) $air->avg(), 1),
            // rainfall е булев-подобно цяло число, не количество валеж.
            'rain' => $bundle->weather->contains(fn (array $w) => (int) ($w['rainfall'] ?? 0) > 0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function pit(RaceDataBundle $bundle, array $people): ?array
    {
        if ($bundle->pits->isEmpty()) {
            return null;
        }

        // През 2026 stop_duration е null в целия сезон — времето на стоене го
        // няма в данните. Затова говорим за времето в ЛЕЙНА и отсяваме
        // стойностите, надути от червен флаг.
        $sane = $bundle->pits->filter(function (array $p): bool {
            $lane = $p['lane_duration'] ?? null;

            return is_numeric($lane) && (float) $lane > 0 && (float) $lane <= self::MAX_SANE_LANE_SECONDS;
        });

        $fastest = $sane->sortBy(fn (array $p) => (float) $p['lane_duration'])->first();

        return [
            'total' => $bundle->pits->count(),
            'fastest_lane' => $fastest === null ? null : [
                'name' => $people[(int) $fastest['driver_number']]['name'] ?? ('#'.$fastest['driver_number']),
                'seconds' => round((float) $fastest['lane_duration'], 1),
                'lap' => (int) $fastest['lap_number'],
            ],
        ];
    }

    /**
     * Най-дългата битка: кой е карал най-дълго на под секунда зад болида пред
     * себе си.
     *
     * Това е единственото, което `intervals` дава и обиколките не могат —
     * времената по обиколка не виждат какво става ВЪТРЕ в обиколката. Кой е бил
     * отпред не се казва: endpoint-ът носи разстоянието, но не и позицията, а
     * измислено име е по-лошо от липсващо.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array<string, mixed>|null
     */
    private function battle(RaceDataBundle $bundle, array $people): ?array
    {
        if ($bundle->intervals->isEmpty()) {
            return null;
        }

        $best = null;

        foreach ($bundle->intervals->groupBy(fn (array $r) => (int) $r['driver_number']) as $number => $rows) {
            $sorted = $rows->sortBy(fn (array $r) => (string) $r['date'])->values();
            $runStart = null;
            $previous = null;

            foreach ($sorted as $row) {
                $at = strtotime((string) $row['date']);
                $close = is_numeric($row['interval'] ?? null) && (float) $row['interval'] <= 1.0;

                if ($at === false) {
                    continue;
                }

                if (! $close) {
                    $runStart = null;

                    continue;
                }

                $runStart ??= $at;
                $previous = $at;
                $seconds = $previous - $runStart;

                if ($best === null || $seconds > $best['seconds']) {
                    $best = ['number' => (int) $number, 'seconds' => $seconds];
                }
            }
        }

        // Под минута не е битка, а един завой — не си струва изречението.
        if ($best === null || $best['seconds'] < 60) {
            return null;
        }

        return [
            'name' => $people[$best['number']]['name'] ?? ('#'.$best['number']),
            'colour' => $people[$best['number']]['colour'] ?? '#83838d',
            'minutes' => (int) round($best['seconds'] / 60),
        ];
    }

    /**
     * Радиото: НЕ препубликуваме звука — той е на Формула 1, а не наш. Ползваме
     * само кога са се обаждали, което е добър индикатор коя обиколка е била
     * най-събитийна.
     *
     * През 2026 покритието официално е рухнало, така че липсата е нормална.
     *
     * @return array<string, mixed>|null
     */
    private function radio(RaceDataBundle $bundle): ?array
    {
        if ($bundle->teamRadio->isEmpty()) {
            return null;
        }

        $starts = $bundle->laps
            ->filter(fn (array $l) => filled($l['date_start'] ?? null))
            ->map(fn (array $l) => ['lap' => (int) $l['lap_number'], 'at' => strtotime((string) $l['date_start'])])
            ->filter(fn (array $l) => $l['at'] !== false)
            ->sortBy('at')
            ->values();

        if ($starts->isEmpty()) {
            return ['count' => $bundle->teamRadio->count()];
        }

        $perLap = [];

        foreach ($bundle->teamRadio as $message) {
            $at = strtotime((string) ($message['date'] ?? ''));

            if ($at === false) {
                continue;
            }

            $lap = $starts->last(fn (array $l) => $l['at'] <= $at);

            if ($lap !== null) {
                $perLap[$lap['lap']] = ($perLap[$lap['lap']] ?? 0) + 1;
            }
        }

        arsort($perLap);
        $busiest = array_key_first($perLap);

        return array_filter([
            'count' => $bundle->teamRadio->count(),
            // Само ако наистина се откроява — две съобщения не правят „най-събитийна“.
            'busiest_lap' => $busiest !== null && $perLap[$busiest] >= 3 ? (int) $busiest : null,
            'busiest_count' => $busiest !== null && $perLap[$busiest] >= 3 ? $perLap[$busiest] : null,
        ], fn ($value) => $value !== null);
    }

    private function winnerNumber(RaceDataBundle $bundle): ?int
    {
        $first = $bundle->result->first(fn (array $r) => (int) ($r['position'] ?? 0) === 1);

        return $first === null ? null : (int) $first['driver_number'];
    }

    /** Секунди във вид „1:23.456“. */
    public static function lapTime(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $rest = $seconds - $minutes * 60;

        return sprintf('%d:%06.3f', $minutes, $rest);
    }
}
