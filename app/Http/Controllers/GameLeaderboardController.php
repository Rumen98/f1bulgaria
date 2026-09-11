<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameLapRequest;
use App\Services\Game\LeaderboardService;
use App\Services\Game\WeekTrackResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameLeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly WeekTrackResolver $weekTrack,
    ) {}

    /**
     * Лилавите рекорди + топ класация за пистата. Публично (без вход): гостите
     * виждат лилавите времена за сравнение, но не записват. За пистата на
     * уикенда идва и седмичната класация — тя се нулира всеки уикенд и дава
     * на всекиго шанс да е №1.
     */
    public function show(Request $request, string $track): JsonResponse
    {
        abort_unless(array_key_exists($track, (array) config('game.tracks', [])), 404);

        $user = $request->user();

        $week = $this->weekTrack->resolve();
        $weekly = $week !== null && $week['slug'] === $track
            ? $this->leaderboard->topLaps($track, $user, 10, $week['week_start'])
            : null;

        return response()->json([
            'bests' => $this->leaderboard->bests($track),
            'top' => $this->leaderboard->topLaps($track, $user),
            'weekly' => $weekly,
            'user_bests' => $user !== null
                ? $this->leaderboard->userBests($user, $track)
                : ['lap_ms' => null, 'sectors_ms' => [null, null, null]],
            'authenticated' => $user !== null,
        ]);
    }

    /**
     * Сървърният дух на потребител за пистата — кадрите на най-добрата му
     * потвърдена обиколка, за дуелите „Карай срещу…". Публично: духът е
     * общностно съдържание, както класацията.
     */
    public function ghost(string $track, int $user): JsonResponse
    {
        abort_unless(array_key_exists($track, (array) config('game.tracks', [])), 404);

        $record = $this->leaderboard->ghostOf($user, $track);

        abort_if($record === null, 404);

        return response()->json([
            'v' => $record->sim_version,
            'lap_ms' => $record->lap_ms,
            'lap_ticks' => $record->lap_ticks,
            'frames' => $record->ghost_frames,
            'name' => $record->user->name,
        ]);
    }

    /**
     * Записва завършена квалификационна обиколка и връща какво е постигнала
     * (лилави полета, личен рекорд, позиция).
     */
    public function store(StoreGameLapRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var array{0: int, 1: int, 2: int} $sectors */
        $sectors = array_map('intval', $data['sectors']);

        $result = $this->leaderboard->record(
            $request->user(),
            $data['track'],
            (int) $data['lap_ms'],
            $sectors,
            $data['trace'] ?? null,
            isset($data['sim_version']) ? (int) $data['sim_version'] : null,
        );

        return response()->json($result);
    }
}
