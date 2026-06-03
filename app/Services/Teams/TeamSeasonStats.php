<?php

declare(strict_types=1);

namespace App\Services\Teams;

/**
 * Сезонна статистика на отбор (конструктор).
 */
final readonly class TeamSeasonStats
{
    public function __construct(
        public ?int $position,
        public float $points,
        public int $wins,
        public int $podiums,
        public int $poles,
        public int $fastestLaps,
        public int $dnfs,
    ) {}

    /**
     * @return array<string, int|float|null>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'points' => $this->points,
            'wins' => $this->wins,
            'podiums' => $this->podiums,
            'poles' => $this->poles,
            'fastest_laps' => $this->fastestLaps,
            'dnfs' => $this->dnfs,
        ];
    }
}
