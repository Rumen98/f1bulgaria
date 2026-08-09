<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizRequest;
use App\Models\QuizQuestion;
use App\Services\Quiz\QuizScoringService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class QuizController extends Controller
{
    public function index(): Response
    {
        $count = (int) config('quiz.count', 10);

        $questions = QuizQuestion::query()
            ->active()
            ->inRandomOrder()
            ->limit($count)
            ->get()
            ->map(fn (QuizQuestion $q) => [
                'id' => $q->id,
                'question' => $q->question,
                'options' => $q->optionsList(),   // БЕЗ correct_option
            ])
            ->values();

        return Inertia::render('Quiz/Index', [
            'questions' => $questions,
            'result' => null,
        ]);
    }

    public function score(StoreQuizRequest $request, QuizScoringService $scoring): Response|RedirectResponse
    {
        /** @var array<int, array{id: int, choice: int|null}> $answers */
        $answers = $request->validated()['answers'];

        $result = $scoring->score($answers);

        if ($result['review'] === []) {
            // Всички изпратени id-та са непознати/деактивирани — резултат 0/0 е безсмислен.
            return to_route('quiz');
        }

        // Рендер върху POST е умишлен trade-off: нищо не се персистира, затова
        // няма смислен GET, към който да redirect-нем; резултатът живее само в
        // този POST отговор.
        return Inertia::render('Quiz/Index', [
            'questions' => [],
            'result' => $result,
        ]);
    }
}
