<?php

declare(strict_types=1);

namespace App\Services\Predictions;

use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Класиране на prediction league-а — сбор от точките на потребителите за сезон.
 */
class LeaderboardService
{
    /**
     * @return Collection<int, array{position:int, user:User, points:int, predictions:int}>
     */
    public function forSeason(Season $season): Collection
    {
        $rows = User::query()
            ->select('users.id', 'users.name', 'users.avatar_path')
            ->selectRaw('COALESCE(SUM(prediction_scores.points), 0) as points')
            ->selectRaw('COUNT(DISTINCT predictions.id) as predictions_count')
            ->join('predictions', 'predictions.user_id', '=', 'users.id')
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->leftJoin('prediction_scores', 'prediction_scores.prediction_id', '=', 'predictions.id')
            ->where('races.season_id', $season->id)
            ->groupBy('users.id', 'users.name', 'users.avatar_path')
            ->orderByDesc('points')
            ->orderByDesc('predictions_count')
            ->get();

        return $rows->values()->map(fn ($row, $i) => [
            'position' => $i + 1,
            'user' => $row,
            'points' => (int) $row->points,
            'predictions' => (int) $row->predictions_count,
        ]);
    }

    /**
     * Статистика на конкретен потребител за сезон.
     *
     * @return array{points:int, predictions:int, best:int, average:float}
     */
    public function userStats(User $user, Season $season): array
    {
        $scores = $user->predictions()
            ->whereHas('race', fn ($q) => $q->where('season_id', $season->id))
            ->with('score')
            ->get()
            ->map(fn ($prediction) => $prediction->score?->points ?? 0);

        $count = $scores->count();

        return [
            'points' => (int) $scores->sum(),
            'predictions' => $count,
            'best' => (int) ($scores->max() ?? 0),
            'average' => $count > 0 ? round($scores->sum() / $count, 1) : 0.0,
        ];
    }
}
