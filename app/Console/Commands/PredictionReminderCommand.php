<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PredictionReminderMail;
use App\Models\NewsletterSend;
use App\Models\Race;
use App\Models\Season;
use App\Models\User;
use App\Services\Predictions\PredictionLockService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Подсеща с имейл само тези потребители, които НЯМАТ прогноза за най-близкия
 * кръг, малко преди заключването.
 *
 * Това е единственото писмо на Падок, което зависи от поведението на човека —
 * подалите прогноза не получават нищо, така че не се превръща в шум.
 */
class PredictionReminderCommand extends Command
{
    protected $signature = 'f1:prediction-reminder
        {--race= : ID на състезание (ръчен пуск, заобикаля прозореца)}
        {--force : Праща дори ако вече е пращано за този кръг}
        {--dry-run : Само отчита кой би получил писмо}';

    protected $description = 'Подсеща потребителите без подадена прогноза, преди заключването на кръга.';

    /** Прозорец преди заключването, в който писмото има смисъл. */
    private const HOURS_BEFORE_LOCK = 24;

    public function handle(PredictionLockService $locks): int
    {
        $race = $this->resolveRace($locks);

        if ($race === null) {
            $this->info('Няма кръг със заключване в следващите '.self::HOURS_BEFORE_LOCK.' часа — пропускаме.');

            return self::SUCCESS;
        }

        $deadline = $locks->lockDeadline($race);

        if ($deadline === null || $deadline->isPast()) {
            $this->warn("Кръг [{$race->id}] няма бъдещ краен срок — пропускаме.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->alreadySent($race)) {
            $this->info("Вече е пращано подсещане за кръг [{$race->id}] — пропускаме.");

            return self::SUCCESS;
        }

        $recipients = $this->recipients($race);

        if ($recipients->isEmpty()) {
            $this->info('Всички потребители вече имат прогноза за този кръг — няма на кого да пишем.');
            $this->markSent($race);

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$recipients->count()} потребители биха получили подсещане за „{$race->name_bg}“.");

            return self::SUCCESS;
        }

        $deadlineText = $deadline->setTimezone('Europe/Sofia')->format('d.m.Y, H:i').' ч.';
        $hoursLeft = $this->humanRemaining($deadline);

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new PredictionReminderMail(
                $race,
                $deadlineText,
                $hoursLeft,
                URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        $this->markSent($race);

        $this->info("Подсещането е в опашката: {$recipients->count()} потребители без прогноза за „{$race->name_bg}“.");

        return self::SUCCESS;
    }

    private function resolveRace(PredictionLockService $locks): ?Race
    {
        if ($id = $this->option('race')) {
            return Race::query()->find($id);
        }

        $season = Season::current();

        if ($season === null) {
            return null;
        }

        // Прозорецът се мери спрямо квалификацията, защото заключването виси на
        // нея, а не на самото състезание (спринтовите уикенди го изместват).
        return $season->races()
            ->whereNotNull('qualifying_datetime_utc')
            ->where('qualifying_datetime_utc', '>', now())
            ->orderBy('qualifying_datetime_utc')
            ->get()
            ->first(function (Race $race) use ($locks): bool {
                $deadline = $locks->lockDeadline($race);

                return $deadline !== null
                    && $deadline->isFuture()
                    && $deadline->lessThanOrEqualTo(now()->addHours(self::HOURS_BEFORE_LOCK));
            });
    }

    /**
     * Потребители с акаунт, които не са банати, не са спрели имейлите и нямат
     * прогноза за този кръг.
     *
     * @return Collection<int, User>
     */
    private function recipients(Race $race): Collection
    {
        return User::query()
            ->whereNull('banned_at')
            ->whereNull('email_opt_out_at')
            ->whereDoesntHave('predictions', fn ($query) => $query->where('race_id', $race->id))
            ->get();
    }

    private function alreadySent(Race $race): bool
    {
        return NewsletterSend::query()
            ->where('mail_type', NewsletterSend::TYPE_PREDICTION_REMINDER)
            ->where('race_id', $race->id)
            ->exists();
    }

    private function markSent(Race $race): void
    {
        NewsletterSend::create([
            'mail_type' => NewsletterSend::TYPE_PREDICTION_REMINDER,
            'race_id' => $race->id,
            'sent_at' => Carbon::now(),
        ]);
    }

    /**
     * „след около 6 часа“ / „след по-малко от час“ — по-четимо от гола дата.
     */
    private function humanRemaining(CarbonInterface $deadline): ?string
    {
        $hours = (int) floor(now()->diffInHours($deadline, absolute: true));

        if ($hours < 1) {
            return 'след по-малко от час';
        }

        return "след около {$hours} ".($hours === 1 ? 'час' : 'часа');
    }
}
