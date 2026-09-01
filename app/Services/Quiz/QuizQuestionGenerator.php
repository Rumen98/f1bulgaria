<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizQuestion;
use App\Services\News\Llm\LlmClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Генерира куиз въпроси с двойна независима проверка и ги пуска НАПРАВО
 * активни — без човешко одобрение по изричния избор на собственика.
 *
 * Защитата е в конвейера, не в човека: генераторът пише само устойчиви
 * исторически факти, а всеки кандидат се дава на ДВА отделни проверителя,
 * които отговарят на въпроса сляпо (без да виждат кой отговор е обявен за
 * верен). Въпросът оцелява само при: двама познали обявения отговор + high
 * увереност + нула двусмислие. Буквални 100% при LLM няма — затова
 * source_note остава в базата като следа за проверка при съмнение, а
 * Filament може да деактивира въпрос за секунди.
 */
class QuizQuestionGenerator
{
    private const DRAFT_TOOL = 'draft_quiz_questions';

    private const VERIFY_TOOL = 'answer_quiz_question';

    /** Колко независими проверителя трябва да са единодушни. */
    private const VERIFIERS = 2;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @return array{drafted:int, saved:int, rejected:int, duplicates:int, reasons:array<int, string>}
     */
    public function generate(int $count): array
    {
        $stats = ['drafted' => 0, 'saved' => 0, 'rejected' => 0, 'duplicates' => 0, 'reasons' => []];

        $existing = QuizQuestion::query()->pluck('question');
        $existingNormalized = $existing
            ->map(fn (string $q) => $this->normalize($q))
            ->flip();

        $candidates = $this->draft($count, $existing->all());
        $stats['drafted'] = count($candidates);

        foreach ($candidates as $candidate) {
            if (! $this->isWellFormed($candidate)) {
                $stats['rejected']++;
                $stats['reasons'][] = 'невалидна структура: '.($candidate['question'] ?? '(без въпрос)');

                continue;
            }

            if ($existingNormalized->has($this->normalize($candidate['question']))) {
                $stats['duplicates']++;

                continue;
            }

            $verdict = $this->verify($candidate);

            if ($verdict !== null) {
                $stats['rejected']++;
                $stats['reasons'][] = "{$candidate['question']} — {$verdict}";

                continue;
            }

            QuizQuestion::create([
                'question' => $candidate['question'],
                'option_1' => $candidate['options'][0],
                'option_2' => $candidate['options'][1],
                'option_3' => $candidate['options'][2],
                'option_4' => $candidate['options'][3],
                'correct_option' => $candidate['correct_option'],
                'source_note' => $candidate['source_note'] ?? null,
                'is_active' => true,
            ]);

            // Новият въпрос влиза в ротацията веднага — може да допълни и
            // текущия седмичен набор, което е желано: повече за решаване.
            $existingNormalized->put($this->normalize($candidate['question']), true);
            $stats['saved']++;
        }

        return $stats;
    }

    /**
     * @param  array<int, string>  $existing
     * @return array<int, array<string, mixed>>
     */
    private function draft(int $count, array $existing): array
    {
        $existingList = $existing === []
            ? '(няма)'
            : implode("\n", array_map(fn (string $q) => "- {$q}", $existing));

        $response = $this->llm->completeWithTool(
            (string) config('quiz.generator_system_prompt'),
            "Напиши {$count} нови въпроса.\n\nСъществуващи въпроси (НЕ ги повтаряй и не ги перифразирай):\n{$existingList}",
            self::DRAFT_TOOL,
            [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string', 'maxLength' => 500],
                                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 4, 'maxItems' => 4],
                                'correct_option' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                                'source_note' => ['type' => 'string', 'maxLength' => 500],
                            ],
                            'required' => ['question', 'options', 'correct_option', 'source_note'],
                        ],
                    ],
                ],
                'required' => ['questions'],
            ],
            4096,
        );

        return array_values((array) ($response['input']['questions'] ?? []));
    }

    /**
     * Двама независими проверителя отговарят сляпо. null = одобрен;
     * иначе — причината за отказ.
     *
     * @param  array{question:string, options:array<int,string>, correct_option:int}  $candidate
     */
    private function verify(array $candidate): ?string
    {
        $optionsText = collect($candidate['options'])
            ->map(fn (string $opt, int $i) => ($i + 1).'. '.$opt)
            ->implode("\n");

        for ($i = 1; $i <= self::VERIFIERS; $i++) {
            try {
                $response = $this->llm->completeWithTool(
                    (string) config('quiz.verifier_system_prompt'),
                    "Въпрос: {$candidate['question']}\n\nОпции:\n{$optionsText}",
                    self::VERIFY_TOOL,
                    [
                        'type' => 'object',
                        'properties' => [
                            'chosen_option' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'low']],
                            'ambiguous' => ['type' => 'boolean'],
                        ],
                        'required' => ['chosen_option', 'confidence', 'ambiguous'],
                    ],
                    512,
                );
            } catch (Throwable $e) {
                Log::warning("Quiz verifier {$i} се провали: {$e->getMessage()}");

                return "проверител {$i} недостъпен";
            }

            $verdict = $response['input'];

            if ((bool) ($verdict['ambiguous'] ?? true)) {
                return "проверител {$i}: двусмислен";
            }

            if (($verdict['confidence'] ?? 'low') !== 'high') {
                return "проверител {$i}: ниска увереност";
            }

            if ((int) ($verdict['chosen_option'] ?? 0) !== (int) $candidate['correct_option']) {
                return "проверител {$i}: посочи друг отговор";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function isWellFormed(array $candidate): bool
    {
        $options = array_values((array) ($candidate['options'] ?? []));

        return filled($candidate['question'] ?? null)
            && count($options) === 4
            && count(array_filter($options, 'filled')) === 4
            && count(array_unique(array_map('mb_strtolower', $options))) === 4
            && in_array((int) ($candidate['correct_option'] ?? 0), [1, 2, 3, 4], true);
    }

    private function normalize(string $question): string
    {
        return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question) ?? $question));
    }
}
