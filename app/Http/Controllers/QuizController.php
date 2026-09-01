<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizRequest;
use App\Models\QuizQuestion;
use App\Services\Quiz\QuizProgressService;
use App\Services\Quiz\QuizScoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class QuizController extends Controller
{
    public function index(QuizProgressService $progress): Response
    {
        $user = request()->user();

        // Въпросите на СЕДМИЦАТА: един и същ набор за всички, нов всеки
        // понеделник — виж QuizProgressService::weeklyQuestions().
        $weekly = $progress->weeklyQuestions();

        // Един опит на въпрос — завинаги: отговорен (вярно ИЛИ грешно)
        // въпрос не се показва повече. Точки идват само от нови въпроси.
        $spentIds = [];
        $weeklyPoints = 0;

        if ($user !== null) {
            $pivots = $user->answeredQuizQuestions()
                ->whereIn('quiz_questions.id', $weekly->pluck('id'))
                ->get();

            foreach ($pivots as $answered) {
                $spentIds[] = $answered->id;
                $weeklyPoints += $answered->pivot->first_correct_at !== null ? 1 : 0;
            }
        }

        $questions = $weekly
            ->reject(fn (QuizQuestion $q) => in_array($q->id, $spentIds, true))
            ->map(fn (QuizQuestion $q) => [
                'id' => $q->id,
                'question' => $q->question,
                'options' => $q->optionsList(),   // БЕЗ correct_option
            ])
            ->values();

        return Inertia::render('Quiz/Index', [
            'questions' => $questions,
            'result' => null,
            'stats' => $progress->statsFor($user),
            'leaderboard' => $progress->leaderboard(),
            'week' => (int) Carbon::now('Europe/Sofia')->isoWeek(),
            'weeklyTotal' => $weekly->count(),
            'weeklyAnswered' => count($spentIds),
            'weeklyPoints' => $weeklyPoints,
        ]);
    }

    public function score(
        StoreQuizRequest $request,
        QuizScoringService $scoring,
        QuizProgressService $progress,
    ): Response|RedirectResponse {
        /** @var array<int, array{id: int, choice: int|null}> $answers */
        $answers = $request->validated()['answers'];

        $result = $scoring->score($answers);

        if ($result['review'] === []) {
            // Всички изпратени id-та са непознати/деактивирани — резултат 0/0 е безсмислен.
            return to_route('quiz');
        }

        // Историята и точките се пазят само за влезли потребители; гостите
        // играят точно както преди — нищо не се персистира.
        $user = $request->user();
        $result['new_points'] = $user !== null ? $progress->record($user, $result['review']) : 0;

        // Рендер върху POST е умишлен trade-off: нищо не се персистира за гост,
        // затова няма смислен GET, към който да redirect-нем; резултатът живее
        // само в този POST отговор.
        return Inertia::render('Quiz/Index', [
            'questions' => [],
            'result' => $result,
            'stats' => $progress->statsFor($user),
            'leaderboard' => $progress->leaderboard(),
        ]);
    }
}
