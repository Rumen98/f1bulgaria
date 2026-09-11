<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use Illuminate\Support\Collection;

/**
 * Суровият отговор на OpenF1 за едно състезание, плюс шепата изводи, които и
 * фактите, и графиките ползват — за да не се смятат два пъти и да не се
 * разминават.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class RaceDataBundle
{
    /**
     * Кумулативните времена минават през всяка обиколка на всеки пилот и се
     * искат от няколко места (позиции, изоставане, моменти), затова се пазят.
     *
     * Заради това свойство класът НЕ е `readonly` — readonly клас не позволява
     * променливо поле. Самите колекции остават readonly и отвън нищо не се мени.
     *
     * @var array<int, array<int, float>>|null
     */
    private ?array $cumulative = null;

    /**
     * @param  Collection<int, array<string, mixed>>  $drivers
     * @param  Collection<int, array<string, mixed>>  $result
     * @param  Collection<int, array<string, mixed>>  $laps
     * @param  Collection<int, array<string, mixed>>  $stints
     * @param  Collection<int, array<string, mixed>>  $pits
     * @param  Collection<int, array<string, mixed>>  $raceControl
     * @param  Collection<int, array<string, mixed>>  $weather
     * @param  Collection<int, array<string, mixed>>  $grid
     * @param  Collection<int, array<string, mixed>>  $overtakes
     * @param  Collection<int, array<string, mixed>>  $championshipDrivers
     * @param  Collection<int, array<string, mixed>>  $championshipTeams
     * @param  Collection<int, array<string, mixed>>  $intervals
     * @param  Collection<int, array<string, mixed>>  $teamRadio
     */
    public function __construct(
        public readonly Collection $drivers,
        public readonly Collection $result,
        public readonly Collection $laps,
        public readonly Collection $stints,
        public readonly Collection $pits,
        public readonly Collection $raceControl,
        public readonly Collection $weather,
        public readonly Collection $grid,
        public readonly Collection $overtakes,
        public readonly Collection $championshipDrivers,
        public readonly Collection $championshipTeams,
        public readonly Collection $intervals,
        public readonly Collection $teamRadio,
    ) {}

    /**
     * Пилотите по номер.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function driverIndex(): Collection
    {
        return $this->drivers->keyBy(fn (array $d) => (int) $d['driver_number']);
    }

    /**
     * Обиколките, годни за сравнение на темпо.
     *
     * Изрязват се три неща, всяко от които иначе прави графиката шум:
     * обиколката на излизане от пита (гумите са студени, времето е фалшиво),
     * обиколката на влизане (кара се към лейна) и всичко под неутрализация.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cleanLaps(): Collection
    {
        $neutralised = $this->neutralisedLaps();

        $inLaps = $this->pits
            ->map(fn (array $p) => ((int) $p['driver_number']).':'.((int) $p['lap_number']))
            ->flip();

        return $this->laps->filter(function (array $lap) use ($neutralised, $inLaps): bool {
            if (! isset($lap['lap_duration']) || ! is_numeric($lap['lap_duration'])) {
                return false;
            }

            if (($lap['is_pit_out_lap'] ?? false) === true) {
                return false;
            }

            $number = (int) $lap['lap_number'];

            if (isset($neutralised[$number])) {
                return false;
            }

            return ! $inLaps->has(((int) $lap['driver_number']).':'.$number);
        })->values();
    }

    /**
     * Обиколките под safety car, VSC или червен флаг, като карта [обиколка => true].
     *
     * OpenF1 няма изричен маркер за край на пълен safety car — краят се
     * извежда от зелен флаг или от текста „IN THIS LAP“. Ако краят изобщо
     * липсва (дупка в данните), прозорецът се затваря принудително след
     * MAX_OPEN_LAPS обиколки: по-добре да пропуснем няколко обиколки, отколкото
     * да обявим цялото състезание за неутрализирано и графиките да излязат празни.
     *
     * @return array<int, true>
     */
    public function neutralisedLaps(): array
    {
        $maxOpenLaps = 6;
        $out = [];
        $openedAt = null;

        foreach ($this->sortedRaceControl() as $message) {
            $lap = isset($message['lap_number']) ? (int) $message['lap_number'] : null;

            if ($lap === null) {
                continue;
            }

            if ($this->endsNeutralisation($message)) {
                if ($openedAt !== null) {
                    $this->markRange($out, $openedAt, $lap);
                    $openedAt = null;
                }

                continue;
            }

            if ($this->startsNeutralisation($message) && $openedAt === null) {
                $openedAt = $lap;
            }

            if ($openedAt !== null && $lap - $openedAt > $maxOpenLaps) {
                $this->markRange($out, $openedAt, $openedAt + $maxOpenLaps);
                $openedAt = null;
            }
        }

        if ($openedAt !== null) {
            $this->markRange($out, $openedAt, $openedAt + $maxOpenLaps);
        }

        return $out;
    }

    /**
     * Прозорците на неутрализация като двойки [от, до] обиколки — за лентите
     * под графиките.
     *
     * @return array<int, array{from:int, to:int}>
     */
    public function neutralisationWindows(): array
    {
        $laps = array_keys($this->neutralisedLaps());
        sort($laps);

        $windows = [];
        $from = null;
        $previous = null;

        foreach ($laps as $lap) {
            if ($from === null) {
                $from = $lap;
            } elseif ($previous !== null && $lap > $previous + 1) {
                $windows[] = ['from' => $from, 'to' => $previous];
                $from = $lap;
            }

            $previous = $lap;
        }

        if ($from !== null && $previous !== null) {
            $windows[] = ['from' => $from, 'to' => $previous];
        }

        return $windows;
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
    public function cumulativeTimes(): array
    {
        if ($this->cumulative !== null) {
            return $this->cumulative;
        }

        $medians = $this->medianLapTimes();
        $byDriver = $this->laps->groupBy(fn (array $l) => (int) $l['driver_number']);

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

        return $this->cumulative = $out;
    }

    /** Най-високият номер на обиколка в сесията. */
    public function totalLaps(): int
    {
        return (int) $this->laps->max('lap_number');
    }

    /**
     * @return array<int, float> [обиколка => медиана в секунди]
     */
    private function medianLapTimes(): array
    {
        return $this->laps
            ->filter(fn (array $l) => is_numeric($l['lap_duration'] ?? null))
            ->groupBy(fn (array $l) => (int) $l['lap_number'])
            ->map(function (Collection $laps): float {
                $values = $laps->map(fn (array $l) => (float) $l['lap_duration'])->sort()->values();

                return (float) $values->get((int) floor($values->count() / 2));
            })
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sortedRaceControl(): Collection
    {
        return $this->raceControl->sortBy(fn (array $m) => (string) ($m['date'] ?? ''))->values();
    }

    /** @param  array<string, mixed>  $message */
    private function startsNeutralisation(array $message): bool
    {
        $category = (string) ($message['category'] ?? '');
        $flag = mb_strtoupper((string) ($message['flag'] ?? ''));
        $text = mb_strtoupper((string) ($message['message'] ?? ''));

        return $category === 'SafetyCar'
            || $flag === 'RED'
            || str_contains($text, 'SAFETY CAR')
            || str_contains($text, 'RED FLAG');
    }

    /**
     * Краят се проверява ПРЕДИ началото: „SAFETY CAR IN THIS LAP“ съдържа и
     * двете фрази, а значи край.
     *
     * @param  array<string, mixed>  $message
     */
    private function endsNeutralisation(array $message): bool
    {
        $flag = mb_strtoupper((string) ($message['flag'] ?? ''));
        $text = mb_strtoupper((string) ($message['message'] ?? ''));

        return $flag === 'GREEN'
            || str_contains($text, 'IN THIS LAP')
            || str_contains($text, 'ENDING');
    }

    /** @param  array<int, true>  $out */
    private function markRange(array &$out, int $from, int $to): void
    {
        for ($lap = $from; $lap <= $to; $lap++) {
            $out[$lap] = true;
        }
    }
}
