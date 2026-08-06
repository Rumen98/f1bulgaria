<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuizRequest;
use App\Models\QuizQuestion;
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
            'total' => $questions->count(),
            'result' => null,
        ]);
    }

    public function score(StoreQuizRequest $request): Response
    {
        /** @var array<int, array{id: int, choice: int|null}> $answers */
        $answers = $request->validated()['answers'];
        $ids = array_column($answers, 'id');

        // Верният отговор идва от базата, не от клиента (anti-cheat).
        $questions = QuizQuestion::query()
            ->active()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $score = 0;
        $review = [];

        foreach ($answers as $answer) {
            $question = $questions->get($answer['id']);

            if ($question === null) {
                continue; // непознат/деактивиран id се игнорира
            }

            $choice = $answer['choice'] ?? null;
            $isCorrect = $choice !== null && (int) $choice === $question->correct_option;

            if ($isCorrect) {
                $score++;
            }

            $review[] = [
                'id' => $question->id,
                'question' => $question->question,
                'options' => $question->optionsList(),
                'chosen_option' => $choice,                  // 1..4 или null
                'correct_option' => $question->correct_option, // разкрива се СЛЕД submit
                'is_correct' => $isCorrect,
            ];
        }

        return Inertia::render('Quiz/Index', [
            'questions' => [],
            'total' => count($review),
            'result' => [
                'score' => $score,
                'total' => count($review),
                'review' => $review,
            ],
        ]);
    }
}
