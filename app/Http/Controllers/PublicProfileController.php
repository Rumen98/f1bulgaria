<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ConstructorResource;
use App\Http\Resources\DriverResource;
use App\Models\Season;
use App\Models\User;
use App\Services\Game\LeaderboardService as GameLeaderboardService;
use App\Services\Predictions\LeaderboardService;
use App\Services\Quiz\QuizProgressService;
use Inertia\Inertia;
use Inertia\Response;

class PublicProfileController extends Controller
{
    public function show(
        User $user,
        LeaderboardService $leaderboard,
        QuizProgressService $quiz,
        GameLeaderboardService $game,
    ): Response {
        $season = Season::current();

        $stats = $season
            ? $leaderboard->userStats($user, $season)
            : ['points' => 0, 'predictions' => 0, 'best' => 0, 'average' => 0.0];

        $user->loadMissing(['favoriteDriver.constructor', 'favoriteConstructor', 'badges']);

        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'bio' => $user->bio,
                'avatar_path' => $user->avatar_path,
                'favorite_driver' => $user->favoriteDriver
                    ? new DriverResource($user->favoriteDriver)
                    : null,
                'favorite_constructor' => $user->favoriteConstructor
                    ? new ConstructorResource($user->favoriteConstructor)
                    : null,
                'badges' => $user->badges->map(fn ($badge) => [
                    'name' => $badge->name,
                    'slug' => $badge->slug,
                    'description' => $badge->description,
                    'icon' => $badge->icon,
                    'awarded_at' => $badge->pivot->awarded_at,
                ]),
            ],
            'stats' => $stats,
            'quiz' => $quiz->statsFor($user),
            // Хронометърът: карани писти, първи места, най-силни времена.
            'game' => config('features.game') ? $game->profileStats($user) : null,
            'season' => $season?->year,
        ]);
    }
}
