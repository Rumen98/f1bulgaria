<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Сглобява live класиране за практика/квалификация от OpenF1 данните —
 * подредено по най-добра обиколка, с интервали, сектори и гуми.
 *
 * Race режимът (по трак-позиция и интервали) се добавя в следващ етап.
 */
class LiveStandingsBuilder
{
    public function __construct(private readonly OpenF1Client $client) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(int $sessionKey, string $sessionType): Collection
    {
        return Cache::remember("live-standings:{$sessionKey}", now()->addSeconds(5), function () use ($sessionKey) {
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

            return $this->rankByBestLap($rows);
        });
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
     * Подрежда по най-добра обиколка (без време → накрая) и смята интервалите.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rankByBestLap(Collection $rows): Collection
    {
        $sorted = $rows->sortBy(fn ($r) => $r['best_lap_seconds'] ?? PHP_FLOAT_MAX)->values();
        $leader = $sorted->first()['best_lap_seconds'] ?? null;

        return $sorted->map(function ($row, $i) use ($leader) {
            $row['position'] = $i + 1;

            if ($leader !== null && $row['best_lap_seconds'] !== null && $i > 0) {
                $row['gap_to_leader'] = '+'.number_format($row['best_lap_seconds'] - $leader, 3);
            } elseif ($i === 0 && $row['best_lap_seconds'] !== null) {
                $row['gap_to_leader'] = '—';
            }

            return $row;
        });
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
