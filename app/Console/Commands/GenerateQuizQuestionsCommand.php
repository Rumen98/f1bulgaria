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
 * Целта се брои в ЗАПИСАНИ въпроси, не в чернови. Двойната проверка отсява
 * голяма част от подадените — един кръг от 10 чернови реално дава около 4
 * оцелели, а понеделнишкият анонс обещава 10. Затова командата дописва на
 * кръгове, докато стигне целта или изчерпи quiz.generate_max_rounds.
 *
 * Всеки следващ кръг получава и отпадналите въпроси от предишния: те не влизат
 * в базата, така че без този списък моделът предлага същите отново и се плаща
 * два пъти за същия отказ.
 *
 * От графика върви с --top-up: дописва само когато активните паднат под
 * quiz.pool_target — куизът яде по 10 въпроса на седмица и без поток от нови
 * машината за връщане спира.
 */
class GenerateQuizQuestionsCommand extends Command
{
    protected $signature = 'padok:generate-quiz-questions
        {--count= : Колко НОВИ въпроса да бъдат записани (по подразбиране quiz.generate_batch)}
        {--top-up : Генерира само ако активните въпроси са под quiz.pool_target}';

    protected $description = 'Генерира куиз въпроси с двойна LLM проверка; дописва на кръгове до искания брой.';

    public function handle(QuizQuestionGenerator $generator): int
    {
        $active = QuizQuestion::query()->active()->count();
        $poolTarget = (int) config('quiz.pool_target', 40);

        if ($this->option('top-up') && $active >= $poolTarget) {
            $this->info("Басейнът е пълен: {$active} активни (цел {$poolTarget}) — пропускаме.");

            return self::SUCCESS;
        }

        $batch = max(1, (int) config('quiz.generate_batch', 10));
        $want = (int) ($this->option('count') ?: $batch);

        // При --top-up искаме толкова, колкото липсва до целта, но не повече от
        // един пакет наведнъж: празен басейн иначе би направил десетки заявки
        // към LLM-а в един крон.
        if ($this->option('top-up')) {
            $want = min($want, max(0, $poolTarget - $active));
        }

        if ($want < 1) {
            $this->info('Няма какво да се дописва.');

            return self::SUCCESS;
        }

        $maxRounds = max(1, (int) config('quiz.generate_max_rounds', 4));

        $this->info("Активни: {$active}. Целя {$want} нови въпроса (до {$maxRounds} кръга)…");

        $totals = ['drafted' => 0, 'saved' => 0, 'rejected' => 0, 'duplicates' => 0];
        $reasons = [];
        $avoid = [];

        for ($round = 1; $round <= $maxRounds; $round++) {
            $need = $want - $totals['saved'];

            if ($need < 1) {
                break;
            }

            // С резерв, защото проверката отсява: искането на точно $need
            // гарантира още един кръг за последния въпрос.
            $draftCount = min($batch, max(2, $need * 2));

            $stats = $generator->generate($draftCount, $avoid);

            foreach (['drafted', 'saved', 'rejected', 'duplicates'] as $key) {
                $totals[$key] += $stats[$key];
            }

            $reasons = [...$reasons, ...$stats['reasons']];
            $avoid = [...$avoid, ...$stats['tried']];

            $this->line(sprintf(
                '  кръг %d: %d чернови → %d записани (общо %d/%d)',
                $round,
                $stats['drafted'],
                $stats['saved'],
                $totals['saved'],
                $want,
            ));

            // Нула чернови значи, че доставчикът е недостъпен или моделът е
            // изчерпан. Следващият кръг би платил за същия резултат.
            if ($stats['drafted'] === 0) {
                $this->warn('  генераторът върна нула чернови — спираме.');

                break;
            }
        }

        $this->info(sprintf(
            'Общо · чернови: %d · записани активни: %d · отхвърлени от проверката: %d · дубликати: %d',
            $totals['drafted'],
            $totals['saved'],
            $totals['rejected'],
            $totals['duplicates'],
        ));

        foreach ($reasons as $reason) {
            $this->warn('  ✗ '.$reason);
        }

        if ($totals['saved'] < $want) {
            $this->warn(sprintf(
                'Останахме на %d от %d след %d кръга. Басейнът вече е %d активни.',
                $totals['saved'],
                $want,
                $maxRounds,
                QuizQuestion::query()->active()->count(),
            ));
        }

        return self::SUCCESS;
    }
}
