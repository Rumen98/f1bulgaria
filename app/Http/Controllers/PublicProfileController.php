<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ConstructorResource;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Models\Season;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Predictions\LeaderboardService;
use App\Services\Predictions\PredictionLockService;
use App\Services\Quiz\QuizProgressService;
use App\Services\Races\RaceNameLocalizer;
use App\Support\DriverName;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PublicProfileController extends Controller
{
    public function show(User $user, LeaderboardService $leaderboard, QuizProgressService $quiz): Response
    {
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
                // Всички значки, не само спечелените: заключените показват какво
                // има да се гони. Празният списък иначе не казва нищо.
                'badges' => $this->badges($user),
            ],
            'stats' => $stats,
            'streak' => $season !== null ? $this->streak($user, $season) : 0,
            'predictionHistory' => $this->predictionHistory($user),
            'quiz' => $quiz->statsFor($user),
            'season' => $season?->year,
        ]);
    }

    /**
     * Текущата серия: поредни кръгове с прогноза, броено назад от последния
     * прогнозиран. Механиката зад значката „Постоянство".
     */
    private function streak(User $user, Season $season): int
    {
        $lastRound = $user->predictions()
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->where('races.season_id', $season->id)
            ->max('races.round');

        if ($lastRound === null) {
            return 0;
        }

        return app(BadgeService::class)->predictionStreak($user, $season->id, (int) $lastRound);
    }

    /**
     * Прогнозите по кръгове — САМО за заключени кръгове: отворена прогноза
     * никога не се показва публично, би била подсказка за съперниците.
     *
     * @return array<int, array<string, mixed>>
     */
    private function predictionHistory(User $user): array
    {
        $lock = app(PredictionLockService::class);

        $predictions = $user->predictions()
            ->with(['race', 'score'])
            ->get()
            ->filter(fn ($p) => $p->race !== null && $lock->isLocked($p->race))
            ->sortByDesc(fn ($p) => $p->race->race_datetime_utc)
            ->take(10)
            ->values();

        $driverIds = $predictions
            ->flatMap(fn ($p) => [$p->p1_driver_id, $p->p2_driver_id, $p->p3_driver_id])
            ->filter()
            ->unique();

        $names = Driver::query()
            ->whereIn('id', $driverIds)
            ->get(['id', 'slug', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Driver $d) => [$d->id => DriverName::display($d->slug, $d->fullName())]);

        return $predictions->map(fn ($p) => [
            'race' => app(RaceNameLocalizer::class)->localize($p->race->jolpica_id, $p->race->name),
            'round' => $p->race->round,
            'points' => $p->score?->points,
            'breakdown' => $p->score?->breakdown_json,
            'podium' => collect([$p->p1_driver_id, $p->p2_driver_id, $p->p3_driver_id])
                ->map(fn (?int $id) => $id === null ? null : $names->get($id))
                ->all(),
        ])->all();
    }

    /**
     * Пълният набор значки със състояние „спечелена/заключена".
     *
     * @return array<int, array<string, mixed>>
     */
    private function badges(User $user): array
    {
        $earned = $user->badges->keyBy('slug');

        return collect(BadgeService::DEFINITIONS)
            ->map(fn (array $definition, string $slug) => [
                'slug' => $slug,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'earned' => $earned->has($slug),
                'awarded_at' => ($at = $earned->get($slug)?->pivot->awarded_at) !== null
                    ? Carbon::parse($at)->setTimezone('Europe/Sofia')->format('d.m.Y')
                    : null,
            ])
            // Спечелените отпред, после заключените — по реда на дефиницията.
            ->sortByDesc('earned')
            ->values()
            ->all();
    }
}
