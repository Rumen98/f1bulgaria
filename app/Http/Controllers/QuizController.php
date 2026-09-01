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
        $count = (int) config('quiz.count', 10);
        $user = request()->user();

        // Въпросите на СЕДМИЦАТА: един и същ набор за всички, нов всеки
        // понеделник (ISO седмица, софийско време). Изборът е детерминистичен
        // — md5(id + седмица) като сортов ключ — така куизът става споделен
        // седмичен ритуал вместо различна селекция на всяко зареждане, а
        // хората могат да си сравняват резултатите от един и същ набор.
        $weekKey = Carbon::now('Europe/Sofia')->isoFormat('GGGG-[W]WW');

        $questions = QuizQuestion::query()
            ->active()
            ->get()
            ->sortBy(fn (QuizQuestion $q) => md5($q->id.'|'.$weekKey))
            ->take($count)
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
