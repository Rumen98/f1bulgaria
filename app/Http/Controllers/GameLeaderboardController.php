<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreGameLapRequest;
use App\Services\Game\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameLeaderboardController extends Controller
{
    public function __construct(private readonly LeaderboardService $leaderboard) {}

    /**
     * Лилавите рекорди + топ класация за пистата. Публично (без вход): гостите
     * виждат лилавите времена за сравнение, но не записват.
     */
    public function show(Request $request, string $track): JsonResponse
    {
        abort_unless(array_key_exists($track, (array) config('game.tracks', [])), 404);

        $user = $request->user();

        return response()->json([
            'bests' => $this->leaderboard->bests($track),
            'top' => $this->leaderboard->topLaps($track, $user),
            'user_bests' => $user !== null
                ? $this->leaderboard->userBests($user, $track)
                : ['lap_ms' => null, 'sectors_ms' => [null, null, null]],
            'authenticated' => $user !== null,
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
        );

        return response()->json($result);
    }
}
