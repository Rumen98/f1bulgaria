<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Services\LiveTiming\OpenF1Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Телеметрията на ЕДНА обиколка — най-бързата в състезанието, и за сравнение
 * най-добрата на победителя, ако е друг пилот.
 *
 * Тук е цялата причина рекапът да изглежда като телевизия: скорост, газ,
 * спирачка и предавка по дистанция, плюс линията по трасето, оцветена по
 * скорост.
 *
 * Защо само една обиколка: `car_data` и `location` са на ~3,7 Hz. Цяло
 * състезание за двадесет и двама пилота е половин милион записа. Една обиколка
 * за един пилот е ~325 записа и 37 KB — четири заявки за цялата секция.
 *
 * Дистанцията се ИНТЕГРИРА от скоростта, а не се смята от координатите:
 * документацията на OpenF1 предупреждава, че `location` няма странична
 * точност, така че дължината по x/y е шумна. Скоростта по време е чиста.
 */
class LapTelemetryBuilder
{
    /** Точки в готовата серия. Над това графиката не става по-четима, само по-тежка. */
    private const SAMPLES = 240;

    /** Толкова секунди след обявения край на обиколката още се четат — засечката не е точна. */
    private const TAIL_SECONDS = 1;

    public function __construct(
        private readonly OpenF1Client $client,
        private readonly CircuitGeometry $geometry,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @return array{telemetry: array<int, array<string, mixed>>, track_map: array<string, mixed>|null}
     */
    public function build(RaceDataBundle $bundle, RaceSessionKeys $keys, array $people, ?int $circuitKey, ?int $year): array
    {
        $laps = $this->chosenLaps($bundle);

        if ($laps === []) {
            // Тихото отпадане на телеметрията беше реален проблем на прода:
            // двете най-ефектни графики липсваха, а в лога нямаше нищо.
            Log::warning('Телеметрия: няма обиколка с date_start и валидно време', [
                'session' => $keys->race,
                'laps' => $bundle->laps->count(),
            ]);

            return ['telemetry' => [], 'track_map' => null];
        }

        $telemetry = [];
        $paths = [];

        foreach ($laps as $lap) {
            $driver = (int) $lap['driver_number'];
            $from = $this->parse($lap['date_start']);

            if ($from === null) {
                continue;
            }

            $to = $from->copy()->addSeconds((float) $lap['lap_duration'] + self::TAIL_SECONDS);

            $car = $this->client->getCarData($keys->race, $driver, $from, $to);

            if ($car->count() < 20) {
                Log::warning('Телеметрия: car_data върна твърде малко записи', [
                    'session' => $keys->race,
                    'driver' => $driver,
                    'lap' => $lap['lap_number'] ?? null,
                    'records' => $car->count(),
                ]);

                continue;
            }

            $samples = $this->withDistance($car);
            $person = $people[$driver] ?? null;

            $telemetry[] = [
                'number' => $driver,
                'name' => $person['name'] ?? ('#'.$driver),
                'short' => $person['short'] ?? ('#'.$driver),
                'colour' => $person['colour'] ?? '#83838d',
                'lap' => (int) $lap['lap_number'],
                'time' => RaceFactsBuilder::lapTime((float) $lap['lap_duration']),
                'points' => $this->resample($samples),
            ];

            $path = $this->trackPath($bundle, $keys, $driver, $lap, $samples);

            if ($path !== null) {
                $paths[] = [
                    'number' => $driver,
                    'name' => $person['name'] ?? ('#'.$driver),
                    'short' => $person['short'] ?? ('#'.$driver),
                    'lap' => $path['lap'],
                    'points' => $path['points'],
                ];
            }
        }

        return [
            'telemetry' => $telemetry,
            'track_map' => $this->trackMap($paths, $circuitKey, $year),
        ];
    }

    /**
     * Линията по трасето за картата по скорост — с резервен вариант.
     *
     * Позиционният феед на OpenF1 къса. Проверено за Монако 2026: `location`
     * спира в 14:00:14, а най-бързата обиколка е в 14:10:37 — десет минути
     * след края на покритието. `car_data` за същата обиколка е налично, тоест
     * телеметрията става, а картата не.
     *
     * Затова при празен отговор се пробва още веднъж — с най-бързата обиколка
     * от ПЪРВАТА половина на състезанието, където покритието обикновено го
     * има. Един допълнителен опит, не повече: всяка заявка струва секунда
     * заради разстоянието срещу лимита.
     *
     * Резервният вариант не спасява всичко и това е приемливо. Монако 2026
     * например има 50-минутна дупка от 13:09:42 — реалното покритие през
     * състезанието е около седем минути и никоя разумна евристика няма да го
     * налучка. Тогава картата просто липсва, а логът казва защо.
     *
     * @param  array<string, mixed>  $lap
     * @param  array<int, array<string, mixed>>  $samples
     * @return array{lap:int, points:array<int, array<int, int|float>>}|null
     */
    private function trackPath(RaceDataBundle $bundle, RaceSessionKeys $keys, int $driver, array $lap, array $samples): ?array
    {
        $candidates = [$lap];
        $fallback = $this->earlyLap($bundle, $driver, (int) $lap['lap_number']);

        if ($fallback !== null) {
            $candidates[] = $fallback;
        }

        foreach ($candidates as $index => $candidate) {
            $from = $this->parse($candidate['date_start']);

            if ($from === null) {
                continue;
            }

            $to = $from->copy()->addSeconds((float) $candidate['lap_duration'] + self::TAIL_SECONDS);
            $location = $this->client->getLocation($keys->race, $driver, $from, $to);

            if ($location->count() >= 20) {
                // Резервната обиколка е различна от телеметричната, затова
                // номерът ѝ пътува към фронтенда — иначе картата би твърдяла,
                // че показва обиколка, която не показва.
                return [
                    'lap' => (int) $candidate['lap_number'],
                    'points' => $this->pathWithSpeed($location, $index === 0 ? $samples : $this->samplesFor($keys, $driver, $candidate)),
                ];
            }

            Log::warning('Телеметрия: location няма записи за тази обиколка', [
                'session' => $keys->race,
                'driver' => $driver,
                'lap' => $candidate['lap_number'] ?? null,
                'records' => $location->count(),
            ]);
        }

        return null;
    }

    /**
     * Най-бързата обиколка на пилота от първата половина на състезанието —
     * там, където позиционният феед още не е късал.
     *
     * @return array<string, mixed>|null
     */
    private function earlyLap(RaceDataBundle $bundle, int $driver, int $exclude): ?array
    {
        $half = (int) ceil($bundle->totalLaps() / 2);

        return $bundle->laps
            ->filter(fn (array $l) => (int) $l['driver_number'] === $driver
                && (int) $l['lap_number'] !== $exclude
                && (int) $l['lap_number'] <= $half
                && is_numeric($l['lap_duration'] ?? null)
                && filled($l['date_start'] ?? null)
                && (float) $l['lap_duration'] > 50)
            ->sortBy(fn (array $l) => (float) $l['lap_duration'])
            ->first();
    }

    /**
     * Скоростите за резервната обиколка — картата се оцветява по тях, не по
     * скоростите от друга обиколка.
     *
     * @param  array<string, mixed>  $lap
     * @return array<int, array<string, mixed>>
     */
    private function samplesFor(RaceSessionKeys $keys, int $driver, array $lap): array
    {
        $from = $this->parse($lap['date_start']);

        if ($from === null) {
            return [];
        }

        $to = $from->copy()->addSeconds((float) $lap['lap_duration'] + self::TAIL_SECONDS);
        $car = $this->client->getCarData($keys->race, $driver, $from, $to);

        if ($car->isEmpty()) {
            // Без скорости няма с какво да се оцвети линията и картата отпада
            // мълчаливо. Логва се, защото това е втората най-честа причина да
            // липсва — след липсващите позиции.
            Log::warning('Телеметрия: няма скорости за резервната обиколка', [
                'session' => $keys->race,
                'driver' => $driver,
                'lap' => $lap['lap_number'] ?? null,
            ]);
        }

        return $this->withDistance($car);
    }

    /**
     * Обиколките, за които си струва да платим четири заявки: най-бързата в
     * състезанието и най-добрата на победителя, ако е различен пилот. Повече от
     * две линии на един телеметричен график не се четат.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chosenLaps(RaceDataBundle $bundle): array
    {
        $usable = $bundle->laps->filter(
            fn (array $l) => is_numeric($l['lap_duration'] ?? null)
                && filled($l['date_start'] ?? null)
                && (float) $l['lap_duration'] > 50,
        );

        if ($usable->isEmpty()) {
            return [];
        }

        $fastest = $usable->sortBy(fn (array $l) => (float) $l['lap_duration'])->first();
        $out = [$fastest];

        $winner = $bundle->result->first(fn (array $r) => (int) ($r['position'] ?? 0) === 1);
        $winnerNumber = $winner === null ? null : (int) $winner['driver_number'];

        if ($winnerNumber !== null && $winnerNumber !== (int) $fastest['driver_number']) {
            $best = $usable
                ->where('driver_number', $winnerNumber)
                ->sortBy(fn (array $l) => (float) $l['lap_duration'])
                ->first();

            if ($best !== null) {
                $out[] = $best;
            }
        }

        return $out;
    }

    /**
     * Добавя изминатата дистанция към всеки запис, интегрирайки скоростта.
     *
     * @param  Collection<int, array<string, mixed>>  $car
     * @return array<int, array{d: float, t: float, speed: int, throttle: int, brake: int, gear: int, rpm: int}>
     */
    private function withDistance(Collection $car): array
    {
        $rows = $car->sortBy(fn (array $r) => (string) $r['date'])->values();
        $out = [];
        $distance = 0.0;
        $previous = null;

        foreach ($rows as $row) {
            $at = $this->parse($row['date']);

            if ($at === null) {
                continue;
            }

            // Разликата се смята от щемпелите, а НЕ с floatDiffInSeconds():
            // в Carbon 3 diff методите връщат ЗНАКОВА стойност по подразбиране,
            // така че при подредени по време записи резултатът е отрицателен и
            // дистанцията остава нула. Тихо и трудно за забелязване.
            $seconds = $previous === null
                ? 0.0
                : max(0.0, ($at->getPreciseTimestamp(3) - $previous->getPreciseTimestamp(3)) / 1000);
            $speed = (int) ($row['speed'] ?? 0);
            // км/ч → м/с
            $distance += $speed / 3.6 * $seconds;
            $previous = $at;

            $out[] = [
                'd' => round($distance, 1),
                't' => $at->getPreciseTimestamp(3) / 1000,
                'speed' => $speed,
                'throttle' => (int) ($row['throttle'] ?? 0),
                // brake е 100 при натисната спирачка и 0 иначе — не е процент.
                'brake' => ((int) ($row['brake'] ?? 0)) > 0 ? 1 : 0,
                'gear' => (int) ($row['n_gear'] ?? 0),
                'rpm' => (int) ($row['rpm'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Прорежда серията до SAMPLES точки, като ПАЗИ крайностите: върхът на
     * скоростта и точките на спиране са цялата информация в графиката и не
     * бива да изчезнат заради равномерно прескачане.
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<int, int|float>>
     */
    private function resample(array $samples): array
    {
        $count = count($samples);

        if ($count === 0) {
            return [];
        }

        $step = max(1, (int) ceil($count / self::SAMPLES));
        $out = [];

        for ($i = 0; $i < $count; $i += $step) {
            $chunk = array_slice($samples, $i, $step);
            $median = $this->median($chunk);

            // От всяко късче взимаме записа, който се отклонява НАЙ-МНОГО от
            // медианата му. Средното би загладило точно това, което търсим:
            // върха преди спирачката и дъното в завоя.
            $extreme = $chunk[0];
            $best = abs($chunk[0]['speed'] - $median);

            foreach ($chunk as $row) {
                $deviation = abs($row['speed'] - $median);

                if ($deviation > $best) {
                    $extreme = $row;
                    $best = $deviation;
                }
            }

            $out[] = [
                (int) round($extreme['d']),
                $extreme['speed'],
                $extreme['throttle'],
                $extreme['brake'],
                $extreme['gear'],
            ];
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $chunk */
    private function median(array $chunk): float
    {
        $speeds = array_column($chunk, 'speed');
        sort($speeds);

        return (float) ($speeds[(int) floor(count($speeds) / 2)] ?? 0);
    }

    /**
     * Линията по трасето с цвят по скорост: всяка позиция получава скоростта
     * от най-близкия по време телеметричен запис.
     *
     * @param  Collection<int, array<string, mixed>>  $location
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<int, int|float>>
     */
    private function pathWithSpeed(Collection $location, array $samples): array
    {
        if ($samples === []) {
            return [];
        }

        $rows = $location->sortBy(fn (array $r) => (string) $r['date'])->values();
        $step = max(1, (int) ceil($rows->count() / self::SAMPLES));
        $out = [];
        $cursor = 0;

        foreach ($rows as $index => $row) {
            if ($index % $step !== 0) {
                continue;
            }

            $at = $this->parse($row['date']);

            if ($at === null || ! isset($row['x'], $row['y'])) {
                continue;
            }

            $timestamp = $at->getPreciseTimestamp(3) / 1000;

            // Двете серии са подредени по време, затова курсорът само върви
            // напред — без вложен цикъл по целия масив за всяка точка.
            while ($cursor + 1 < count($samples) && $samples[$cursor + 1]['t'] <= $timestamp) {
                $cursor++;
            }

            $out[] = [
                (int) round((float) $row['x']),
                (int) round((float) $row['y']),
                $samples[$cursor]['speed'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $paths
     * @return array<string, mixed>|null
     */
    private function trackMap(array $paths, ?int $circuitKey, ?int $year): ?array
    {
        $paths = array_values(array_filter($paths, fn (array $p) => count($p['points']) >= 20));

        if ($paths === []) {
            return null;
        }

        $geometry = $this->geometry->forCircuit($circuitKey, $year);

        return [
            // Показваме линията само на един пилот: две наслагани линии по
            // трасето не се различават, а цветът вече носи скоростта.
            'driver' => $paths[0]['name'],
            'short' => $paths[0]['short'],
            'lap' => $paths[0]['lap'] ?? null,
            'points' => $paths[0]['points'],
            'outline' => $geometry['outline'] ?? null,
            'corners' => $geometry['corners'] ?? [],
            // Ъгълът, при който пистата изглежда както по телевизията.
            'rotation' => $geometry['rotation'] ?? 0,
        ];
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
