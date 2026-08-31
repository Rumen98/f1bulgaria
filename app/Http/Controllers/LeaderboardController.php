<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Season;
use App\Services\Predictions\LeaderboardService;
use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    public function index(LeaderboardService $leaderboard): Response
    {
        $season = Season::current();

        $board = $season
            ? $leaderboard->forSeason($season)->map(fn ($row) => [
                'position' => $row['position'],
                'points' => $row['points'],
                'predictions' => $row['predictions'],
                // id-то отваря публичния профил — LeaderboardService вече
                // връща целия User, така че това е нулева нова заявка.
                'id' => $row['user']->id,
                'name' => $row['user']->name,
                'avatar_path' => $row['user']->avatar_path,
            ])
            : collect();

        return Inertia::render('Leaderboard/Index', [
            'season' => $season?->year,
            'leaderboard' => $board,
        ]);
    }
}
