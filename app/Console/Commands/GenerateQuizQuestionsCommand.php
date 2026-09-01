<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuizQuestion;
use App\Services\Quiz\QuizQuestionGenerator;
use Illuminate\Console\Command;

/**
 * Пълни басейна на куиза с LLM въпроси, проверени двойно и пуснати направо
 * активни (виж QuizQuestionGenerator за конвейера на проверката).
 *
 * От графика върви с --top-up: дописва само когато активните паднат под
 * quiz.pool_target — куизът яде по 10 въпроса на седмица на активен играч
 * и без поток от нови машината за връщане спира.
 */
class GenerateQuizQuestionsCommand extends Command
{
    protected $signature = 'padok:generate-quiz-questions
        {--count= : Колко чернови да извади генераторът (по подразбиране quiz.generate_batch)}
        {--top-up : Генерира само ако активните въпроси са под quiz.pool_target}';

    protected $description = 'Генерира куиз въпроси с двойна LLM проверка; оцелелите влизат направо активни.';

    public function handle(QuizQuestionGenerator $generator): int
    {
        $active = QuizQuestion::query()->active()->count();
        $target = (int) config('quiz.pool_target', 40);

        if ($this->option('top-up') && $active >= $target) {
            $this->info("Басейнът е пълен: {$active} активни (цел {$target}) — пропускаме.");

            return self::SUCCESS;
        }

        $count = (int) ($this->option('count') ?: config('quiz.generate_batch', 10));

        $this->info("Активни: {$active}. Генерирам {$count} чернови…");

        $stats = $generator->generate($count);

        $this->info(sprintf(
            'Чернови: %d · записани активни: %d · отхвърлени от проверката: %d · дубликати: %d',
            $stats['drafted'],
            $stats['saved'],
            $stats['rejected'],
            $stats['duplicates'],
        ));

        foreach ($stats['reasons'] as $reason) {
            $this->warn('  ✗ '.$reason);
        }

        return self::SUCCESS;
    }
}
