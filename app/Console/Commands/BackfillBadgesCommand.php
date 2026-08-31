<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Badge;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Еднократно наваксване на значките за вече изиграната част от сезона.
 *
 * Значките се присъждат при синхрона на резултати (evaluateForRace), но той
 * гледа само новоточкуваните кръгове — прогнозите отпреди пускането на
 * значките останаха ненаградени. Командата е идемпотентна (award() пропуска
 * вече присъдени) и може да се пуска спокойно повторно.
 */
class BackfillBadgesCommand extends Command
{
    protected $signature = 'padok:backfill-badges {--dry-run : Само отчита какво би било присъдено}';

    protected $description = 'Синхронизира дефинициите на значките и ги присъжда назад за вече изиграните кръгове.';

    public function handle(BadgeService $badges): int
    {
        if ($this->option('dry-run')) {
            return $this->report();
        }

        // Дефинициите влизат в базата ПРЕДИ присъждането: деплоят не пуска
        // сийдъри, а award() мълчаливо пропуска липсваща значка — без тази
        // стъпка командата би „минала успешно", без да присъди нищо.
        $this->syncDefinitions();

        // „Дебют“ директно за всеки с поне една прогноза: evaluateForRace
        // я дава само на прогнозиралите в кръг С резултати, а прогноза за
        // предстоящ кръг също се брои за дебют.
        $debut = $this->awardDebuts();

        $rest = 0;

        Race::query()
            ->whereHas('predictions.score')
            ->orderBy('race_datetime_utc')
            ->each(function (Race $race) use ($badges, &$rest): void {
                $rest += $badges->evaluateForRace($race);
            });

        $this->info("Присъдени: {$debut} × „Дебют“ + {$rest} от изиграните кръгове.");

        return self::SUCCESS;
    }

    /**
     * Upsert по slug — името/описанието следват кода, без да пипаме
     * чужди редове или вече присъдените връзки.
     */
    private function syncDefinitions(): void
    {
        foreach (BadgeService::DEFINITIONS as $slug => $definition) {
            Badge::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                ],
            );
        }
    }

    private function awardDebuts(): int
    {
        $badge = Badge::query()->where('slug', 'first-prediction')->first();

        if ($badge === null) {
            return 0;
        }

        $missing = User::query()
            ->whereHas('predictions')
            ->whereDoesntHave('badges', fn ($q) => $q->where('badges.id', $badge->id))
            ->get();

        foreach ($missing as $user) {
            // awarded_at = моментът на първата прогноза, не на наваксването —
            // датата на значката трябва да разказва кога е било постижението.
            $firstAt = Prediction::query()
                ->where('user_id', $user->id)
                ->min('created_at');

            $user->badges()->attach($badge->id, ['awarded_at' => $firstAt ?? now()]);
        }

        return $missing->count();
    }

    private function report(): int
    {
        $withPredictions = User::query()->whereHas('predictions')->count();

        $alreadyDebut = Badge::query()->where('slug', 'first-prediction')->exists()
            ? DB::table('badge_user')
                ->join('badges', 'badges.id', '=', 'badge_user.badge_id')
                ->where('badges.slug', 'first-prediction')
                ->count()
            : 0;

        $racesToScan = Race::query()->whereHas('predictions.score')->count();

        $this->info("[dry-run] С прогнози: {$withPredictions} потребители (с „Дебют“ вече: {$alreadyDebut}).");
        $this->info("[dry-run] Кръгове с точкувани прогнози за обхождане: {$racesToScan}.");

        return self::SUCCESS;
    }
}
