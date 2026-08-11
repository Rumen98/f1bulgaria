<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\GameLapRecord;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Класация на Хронометъра: лилави рекорди, топ обиколки и записване на нова
 * квалификационна обиколка.
 *
 * „Лилаво" = най-бързото постигано на пистата — за цялата обиколка и за всеки
 * сектор поотделно. Секторните рекорди се търсят независимо от обиколката, в
 * която са направени (идеална обиколка), точно като в реалния тайминг.
 */
class LeaderboardService
{
    /**
     * Лилавите (рекордни) времена за пистата.
     *
     * @return array{lap_ms: int|null, sectors_ms: array{0: int|null, 1: int|null, 2: int|null}}
     */
    public function bests(string $trackSlug): array
    {
        $row = GameLapRecord::query()
            ->where('track_slug', $trackSlug)
            ->selectRaw('MIN(lap_ms) as lap_ms, MIN(sector1_ms) as s1, MIN(sector2_ms) as s2, MIN(sector3_ms) as s3')
            ->first();

        return [
            'lap_ms' => $row?->lap_ms !== null ? (int) $row->lap_ms : null,
            'sectors_ms' => [
                $row?->s1 !== null ? (int) $row->s1 : null,
                $row?->s2 !== null ? (int) $row->s2 : null,
                $row?->s3 !== null ? (int) $row->s3 : null,
            ],
        ];
    }

    /**
     * Топ N потребители по най-добра обиколка на пистата.
     *
     * @return Collection<int, array{name: string, lap_ms: int, is_you: bool}>
     */
    public function topLaps(string $trackSlug, ?User $viewer = null, int $limit = 10): Collection
    {
        return GameLapRecord::query()
            ->where('game_lap_records.track_slug', $trackSlug)
            ->join('users', 'users.id', '=', 'game_lap_records.user_id')
            ->groupBy('game_lap_records.user_id', 'users.name')
            ->selectRaw('game_lap_records.user_id, users.name, MIN(lap_ms) as lap_ms')
            ->orderByRaw('MIN(lap_ms)')
            ->limit($limit)
            ->get()
            ->map(fn (GameLapRecord $row): array => [
                'name' => (string) $row->name,
                'lap_ms' => (int) $row->lap_ms,
                'is_you' => $viewer !== null && (int) $row->user_id === $viewer->id,
            ]);
    }

    /** Най-добрата обиколка на потребителя за пистата, в милисекунди. */
    public function userBestMs(User $user, string $trackSlug): ?int
    {
        $value = GameLapRecord::query()
            ->where('track_slug', $trackSlug)
            ->where('user_id', $user->id)
            ->min('lap_ms');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Личните рекорди на потребителя (обиколка + по сектори) — за зелено/жълто
     * оцветяване спрямо собственото най-добро.
     *
     * @return array{lap_ms: int|null, sectors_ms: array{0: int|null, 1: int|null, 2: int|null}}
     */
    public function userBests(User $user, string $trackSlug): array
    {
        $row = GameLapRecord::query()
            ->where('track_slug', $trackSlug)
            ->where('user_id', $user->id)
            ->selectRaw('MIN(lap_ms) as lap_ms, MIN(sector1_ms) as s1, MIN(sector2_ms) as s2, MIN(sector3_ms) as s3')
            ->first();

        return [
            'lap_ms' => $row?->lap_ms !== null ? (int) $row->lap_ms : null,
            'sectors_ms' => [
                $row?->s1 !== null ? (int) $row->s1 : null,
                $row?->s2 !== null ? (int) $row->s2 : null,
                $row?->s3 !== null ? (int) $row->s3 : null,
            ],
        ];
    }

    /**
     * Записва квалификационна обиколка и връща какво е постигнала: лилави
     * полета (нов рекорд на пистата), личен рекорд, позиция и обновените
     * рекорди/класация.
     *
     * @param  array{0: int, 1: int, 2: int}  $sectorsMs
     * @return array<string, mixed>
     */
    public function record(User $user, string $trackSlug, int $lapMs, array $sectorsMs): array
    {
        // Рекордите ПРЕДИ тази обиколка — за да знаем кои полета стават лилави
        // (рекорд на всички) и кои зелени (личен рекорд на потребителя).
        $before = $this->bests($trackSlug);
        $userBefore = $this->userBests($user, $trackSlug);

        GameLapRecord::create([
            'user_id' => $user->id,
            'track_slug' => $trackSlug,
            'lap_ms' => $lapMs,
            'sector1_ms' => $sectorsMs[0],
            'sector2_ms' => $sectorsMs[1],
            'sector3_ms' => $sectorsMs[2],
        ]);

        $purpleSectors = [];
        $greenSectors = [];
        foreach ($sectorsMs as $i => $ms) {
            $overall = $before['sectors_ms'][$i];
            $personal = $userBefore['sectors_ms'][$i];
            $purpleSectors[$i] = $overall === null || $ms < $overall;
            $greenSectors[$i] = $personal === null || $ms <= $personal;
        }

        return [
            'purple_lap' => $before['lap_ms'] === null || $lapMs < $before['lap_ms'],
            'purple_sectors' => $purpleSectors,
            'green_sectors' => $greenSectors,
            'personal_best' => $userBefore['lap_ms'] === null || $lapMs <= $userBefore['lap_ms'],
            'rank' => $this->rankOf($trackSlug, $user),
            'bests' => $this->bests($trackSlug),
            'user_bests' => $this->userBests($user, $trackSlug),
            'top' => $this->topLaps($trackSlug, $user),
        ];
    }

    /** Позицията на потребителя в класацията (по неговата най-добра обиколка). */
    private function rankOf(string $trackSlug, User $user): int
    {
        $userBest = $this->userBestMs($user, $trackSlug);

        if ($userBest === null) {
            return 1;
        }

        $ahead = GameLapRecord::query()
            ->where('track_slug', $trackSlug)
            ->where('user_id', '!=', $user->id)
            ->groupBy('user_id')
            ->havingRaw('MIN(lap_ms) < ?', [$userBest])
            ->get(['user_id'])
            ->count();

        return $ahead + 1;
    }
}
