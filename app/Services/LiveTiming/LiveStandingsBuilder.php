<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Сглобява live класиране от OpenF1 данните — с интервали, сектори и гуми.
 *
 * Подредбата зависи от типа сесия:
 * - практика/квалификация → по най-добра обиколка;
 * - състезание/спринт → по реалната позиция на трасето, с изоставане от лидера.
 */
class LiveStandingsBuilder
{
    public function __construct(private readonly OpenF1Client $client) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(int $sessionKey, string $sessionType): Collection
    {
        return Cache::remember("live-standings:{$sessionKey}", now()->addSeconds(5), function () use ($sessionKey, $sessionType) {
            $drivers = $this->client->getSessionDrivers($sessionKey)->keyBy('driver_number');

            if ($drivers->isEmpty()) {
                return collect();
            }

            $lapsByDriver = $this->client->getLatestLaps($sessionKey)->groupBy('driver_number');
            $tyreByDriver = $this->currentTyres($sessionKey);

            $rows = $drivers->map(function (array $driver) use ($lapsByDriver, $tyreByDriver) {
                $laps = $lapsByDriver->get($driver['driver_number'], collect());

                return $this->driverRow($driver, $laps, $tyreByDriver[$driver['driver_number']] ?? null);
            })->values();

            return $this->isRaceSession($sessionType)
                ? $this->rankByTrackPosition($rows, $sessionKey)
                : $this->rankByBestLap($rows);
        });
    }

    /**
     * OpenF1 дава `session_type` = Race и за състезанието, и за спринта
     * (спринтовата квалификация е Qualifying, така че не се хваща тук).
     */
    private function isRaceSession(string $sessionType): bool
    {
        return str_contains(mb_strtolower($sessionType), 'race');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $laps
     * @return array<string, mixed>
     */
    private function driverRow(array $driver, Collection $laps, ?string $tyre): array
    {
        $valid = $laps->filter(fn ($l) => isset($l['lap_duration']) && $l['lap_duration'] > 0);

        $best = $valid->sortBy('lap_duration')->first();
        $last = $laps->sortByDesc('lap_number')->first();

        return [
            'driver_number' => $driver['driver_number'],
            'name' => $driver['full_name'] ?? $driver['name_acronym'] ?? ('#'.$driver['driver_number']),
            'acronym' => $driver['name_acronym'],
            'team_name' => $driver['team_name'],
            'team_colour' => $driver['team_colour'] ?? '#888888',
            'best_lap_seconds' => $best['lap_duration'] ?? null,
            'best_lap_time' => isset($best['lap_duration']) ? $this->formatLap($best['lap_duration']) : null,
            'best_lap_number' => $best['lap_number'] ?? null,
            'last_lap_time' => isset($last['lap_duration']) && $last['lap_duration'] > 0 ? $this->formatLap($last['lap_duration']) : null,
            'sector1_best' => $this->bestSector($valid, 'duration_sector_1'),
            'sector2_best' => $this->bestSector($valid, 'duration_sector_2'),
            'sector3_best' => $this->bestSector($valid, 'duration_sector_3'),
            'current_tire' => $tyre,
            'laps_completed' => $laps->count(),
            'gap_to_leader' => null, // попълва се след подреждане
        ];
    }

    /**
     * Практика/квалификация: подрежда по най-добра обиколка (без време → накрая)
     * и смята изоставането спрямо най-бързия.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rankByBestLap(Collection $rows): Collection
    {
        $sorted = $rows->sortBy(fn ($r) => $r['best_lap_seconds'] ?? PHP_FLOAT_MAX)->values();
        $leader = $sorted->first()['best_lap_seconds'] ?? null;

        $sorted = $sorted->map(function ($row, $i) use ($leader) {
            if ($leader !== null && $row['best_lap_seconds'] !== null) {
                $row['gap_to_leader'] = $i === 0
                    ? '—'
                    : '+'.number_format($row['best_lap_seconds'] - $leader, 3);
            }

            return $row;
        });

        return $this->withSessionBests($sorted);
    }

    /**
     * Състезание/спринт: подрежда по реалната позиция на трасето, а изоставането
     * идва от OpenF1 `intervals` (не от обиколките — там то е безсмислено).
     *
     * Без позиционни данни (старт на сесията / API проблем) падаме към обиколките,
     * за да не остане таблицата в произволен ред.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rankByTrackPosition(Collection $rows, int $sessionKey): Collection
    {
        $positions = $this->latestByDriver($this->client->getPositions($sessionKey), 'position');

        if ($positions === []) {
            return $this->rankByBestLap($rows);
        }

        $gaps = $this->latestByDriver($this->client->getIntervals($sessionKey), 'gap_to_leader');

        $sorted = $rows
            ->sortBy(fn ($r) => $positions[$r['driver_number']] ?? PHP_INT_MAX)
            ->values()
            ->map(function ($row, $i) use ($gaps) {
                $row['gap_to_leader'] = $i === 0
                    ? '—'
                    : $this->formatGap($gaps[$row['driver_number']] ?? null);

                return $row;
            });

        return $this->withSessionBests($sorted);
    }

    /**
     * Последната стойност на `$key` за всеки пилот (по хронология).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    private function latestByDriver(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy('driver_number')
            ->map(fn (Collection $items) => $items->sortBy('date')->last()[$key] ?? null)
            ->filter(fn ($value) => $value !== null)
            ->all();
    }

    /**
     * OpenF1 връща изоставането в секунди (число) или текст „+1 LAP“ за
     * изостаналите с обиколка.
     */
    private function formatGap(mixed $gap): ?string
    {
        if ($gap === null || $gap === '') {
            return null;
        }

        if (is_numeric($gap)) {
            return '+'.number_format((float) $gap, 3);
        }

        return preg_replace('/\s*LAPS?$/i', ' об.', (string) $gap);
    }

    /**
     * Номерира редовете по вече определената подредба и маркира сесийните
     * рекорди (лилаво) за обиколка и сектори.
     *
     * @param  Collection<int, array<string, mixed>>  $sorted
     * @return Collection<int, array<string, mixed>>
     */
    private function withSessionBests(Collection $sorted): Collection
    {
        $bestLap = $sorted->pluck('best_lap_seconds')->filter(fn ($v) => $v !== null)->min();
        $bestS1 = $this->sessionBest($sorted, 'sector1_best');
        $bestS2 = $this->sessionBest($sorted, 'sector2_best');
        $bestS3 = $this->sessionBest($sorted, 'sector3_best');

        return $sorted->map(function ($row, $i) use ($bestLap, $bestS1, $bestS2, $bestS3) {
            $row['position'] = $i + 1;

            $row['is_overall_best'] = $bestLap !== null && $row['best_lap_seconds'] === $bestLap;
            $row['sector1_overall'] = $bestS1 !== null && $row['sector1_best'] === $bestS1;
            $row['sector2_overall'] = $bestS2 !== null && $row['sector2_best'] === $bestS2;
            $row['sector3_overall'] = $bestS3 !== null && $row['sector3_best'] === $bestS3;

            return $row;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function sessionBest(Collection $rows, string $key): ?float
    {
        $values = $rows->pluck($key)->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : (float) $values->min();
    }

    /**
     * Текущата гума на всеки пилот = съставът от последния стинт.
     *
     * @return array<int, string>
     */
    private function currentTyres(int $sessionKey): array
    {
        return $this->client->getStints($sessionKey)
            ->groupBy('driver_number')
            ->map(fn (Collection $stints) => $stints->sortByDesc('lap_start')->first()['compound'] ?? null)
            ->filter()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $laps
     */
    private function bestSector(Collection $laps, string $key): ?float
    {
        $values = $laps->pluck($key)->filter(fn ($v) => is_numeric($v) && $v > 0);

        return $values->isEmpty() ? null : round((float) $values->min(), 3);
    }

    /**
     * 72.345 → „1:12.345"; под 60s → „59.812".
     */
    private function formatLap(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $rest = $seconds - $minutes * 60;

        return $minutes > 0
            ? sprintf('%d:%06.3f', $minutes, $rest)
            : number_format($seconds, 3);
    }
}
