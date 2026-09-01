<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Прогресът на потребител в куиза: история на опитите и „покорени“ въпроси.
 *
 * Точките са БРОЯТ различни въпроси, отговорени вярно поне веднъж — не сборът
 * от опитите. Верните отговори се показват след submit, затова сбор от опити
 * би се фармил с повтаряне. Така таванът расте само когато в базата влязат
 * нови въпроси, което е и причината човек да се върне.
 */
class QuizProgressService
{
    /**
     * Записва предадените отговори при правило „един опит на въпрос",
     * точка: всеки отговор — верен или грешен — изразходва въпроса завинаги.
     * Прегледът след предаване разкрива верните отговори, така че какъвто и
     * да е повторен опит би бил преписване, не знание. Точки идват само от
     * нови въпроси.
     *
     * @param  array<int, array{id: int, is_correct: bool}>  $review  Прегледът от QuizScoringService.
     * @return int Брой НОВИ точки от това предаване.
     */
    public function record(User $user, array $review): int
    {
        if ($review === []) {
            return 0;
        }

        $score = count(array_filter($review, fn (array $row) => $row['is_correct']));

        return DB::transaction(function () use ($user, $review, $score): int {
            QuizAttempt::create([
                'user_id' => $user->id,
                'score' => $score,
                'total' => count($review),
            ]);

            $now = Carbon::now();

            $answeredIds = $user->answeredQuizQuestions()
                ->whereIn('quiz_questions.id', array_column($review, 'id'))
                ->pluck('quiz_questions.id')
                ->all();

            $points = 0;

            foreach ($review as $row) {
                // Отговорен въпрос е отговорен ЗАВИНАГИ — верен или грешен,
                // втори опит няма (прегледът разкрива отговорите). Повторно
                // предаване през API-то просто се игнорира.
                if (in_array($row['id'], $answeredIds, true)) {
                    continue;
                }

                $user->answeredQuizQuestions()->attach($row['id'], [
                    'answered_at' => $now,
                    'first_correct_at' => $row['is_correct'] ? $now : null,
                ]);

                $points += $row['is_correct'] ? 1 : 0;
            }

            return $points;
        });
    }

    /**
     * Статистика за таблото на куиза. При гост връща само общия брой въпроси.
     *
     * Нарочно без броене на опити и „най-добър резултат": куизът е седмичен —
     * отговаряш, събираш точки и чакаш новите въпроси. Историята на опитите
     * остава в quiz_attempts като данни, но не е част от играта.
     *
     * @return array{points:int, available:int}
     */
    public function statsFor(?User $user): array
    {
        $available = QuizQuestion::query()->active()->count();

        if ($user === null) {
            return ['points' => 0, 'available' => $available];
        }

        // Само активни въпроси — деактивиран въпрос не бива да държи точка,
        // иначе точките биха надвишили тавана и процентът би минал 100%.
        $points = $user->masteredQuizQuestions()
            ->where('quiz_questions.is_active', true)
            ->count();

        return ['points' => $points, 'available' => $available];
    }

    /**
     * Топ играчи по брой покорени въпроси. Без точки — извън класацията.
     *
     * @return Collection<int, array{position:int, id:int, name:string, points:int}>
     */
    public function leaderboard(int $limit = 10): Collection
    {
        return User::query()
            ->select('users.id', 'users.name')
            ->whereNull('users.banned_at')
            ->withCount(['masteredQuizQuestions as points' => fn ($query) => $query->where('quiz_questions.is_active', true)])
            // whereHas, а не having: withCount произвежда select подзаявка, а не
            // агрегат с GROUP BY — HAVING върху нея е невалиден SQL.
            ->whereHas('masteredQuizQuestions', fn ($query) => $query->where('quiz_questions.is_active', true))
            ->orderByDesc('points')
            ->orderBy('users.name')
            ->limit($limit)
            ->get()
            ->values()
            ->map(fn (User $user, int $index) => [
                'position' => $index + 1,
                'id' => $user->id,
                'name' => $user->name,
                'points' => (int) $user->points,
            ]);
    }

    /**
     * Брой активни въпроси, които потребителят още не е покорил — двигателят
     * на подсещането в седмичния дайджест.
     */
    public function unmasteredCountFor(User $user): int
    {
        return QuizQuestion::query()
            ->active()
            ->whereNotIn('id', $user->masteredQuizQuestions()->select('quiz_questions.id'))
            ->count();
    }
}
