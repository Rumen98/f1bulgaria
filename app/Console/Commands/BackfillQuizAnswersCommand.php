<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use App\Services\Quiz\QuizProgressService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Еднократно наваксване: маркира като „отговорени" въпросите от миналите
 * предавания, за които няма ред в quiz_question_user.
 *
 * Причината: старият код пазеше ред само при ВЕРЕН отговор, така че
 * историческите грешни отговори са без следа и въпросите им излизат
 * отново — а правилото е един опит завинаги.
 *
 * Реконструкция по извод: предаване от дадена седмица е било върху
 * седмичния набор на същата седмица (наборът е детерминистичен). Това е
 * точно за всичко след пускането на седмичния куиз; по-стари случайни
 * набори не могат да се възстановят и не се пипат.
 */
class BackfillQuizAnswersCommand extends Command
{
    protected $signature = 'padok:backfill-quiz-answers {--dry-run : Само отчита какво би било маркирано}';

    protected $description = 'Маркира миналите предадени куиз въпроси (вкл. сгрешените) като отговорени.';

    public function handle(QuizProgressService $quiz): int
    {
        $marked = 0;
        $dryRun = (bool) $this->option('dry-run');

        // Групираме по потребител + седмица на предаването: наборът на
        // седмицата е общ за всичките му предавания в нея.
        $groups = QuizAttempt::query()
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (QuizAttempt $attempt) => $attempt->user_id
                .'|'.$attempt->created_at->copy()->setTimezone('Europe/Sofia')->isoFormat('GGGG-[W]WW'));

        foreach ($groups as $attempts) {
            $first = $attempts->first();
            $setIds = $quiz->weeklyQuestions($first->created_at)->pluck('id')->all();

            if ($setIds === []) {
                continue;
            }

            $existing = DB::table('quiz_question_user')
                ->where('user_id', $first->user_id)
                ->whereIn('quiz_question_id', $setIds)
                ->pluck('quiz_question_id')
                ->all();

            $missing = array_values(array_diff($setIds, $existing));

            if ($missing === []) {
                continue;
            }

            if (! $dryRun) {
                DB::table('quiz_question_user')->insert(array_map(fn (int $id) => [
                    'user_id' => $first->user_id,
                    'quiz_question_id' => $id,
                    // Моментът на предаването, не на наваксването.
                    'answered_at' => $first->created_at,
                    'first_correct_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $missing));
            }

            $marked += count($missing);
        }

        $prefix = $dryRun ? '[dry-run] Биха били маркирани' : 'Маркирани';
        $this->info("{$prefix}: {$marked} отговорени въпроса от минали предавания.");

        return self::SUCCESS;
    }
}
