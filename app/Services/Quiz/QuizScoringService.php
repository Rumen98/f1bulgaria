<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizQuestion;

/**
 * Оценява отговорите на куиза сървърно — верният отговор идва от базата,
 * не от клиента (anti-cheat). Нищо не се персистира.
 */
class QuizScoringService
{
    /**
     * @param  array<int, array{id: int, choice?: int|null}>  $answers
     * @return array{score: int, total: int, review: array<int, array{id: int, question: string, options: array<int, string>, chosen_option: int|null, correct_option: int, is_correct: bool}>}
     */
    public function score(array $answers): array
    {
        $questions = QuizQuestion::query()
            ->active()
            ->whereIn('id', array_column($answers, 'id'))
            ->get()
            ->keyBy('id');

        $score = 0;
        $review = [];

        foreach ($answers as $answer) {
            $question = $questions->get($answer['id']);

            if ($question === null) {
                continue; // непознат/деактивиран id се игнорира
            }

            $choice = isset($answer['choice']) ? (int) $answer['choice'] : null;
            $isCorrect = $choice !== null && $choice === $question->correct_option;

            if ($isCorrect) {
                $score++;
            }

            $review[] = [
                'id' => $question->id,
                'question' => $question->question,
                'options' => $question->optionsList(),
                'chosen_option' => $choice,                    // 1..4 или null
                'correct_option' => $question->correct_option, // разкрива се СЛЕД submit
                'is_correct' => $isCorrect,
            ];
        }

        return [
            'score' => $score,
            'total' => count($review),
            'review' => $review,
        ];
    }
}
