<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\LiveCoverageMail;
use App\Models\NewsletterSend;
use App\Models\Race;
use App\Models\Season;
use App\Services\LiveTiming\OpenF1TokenManager;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Races\RaceNameLocalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * „Днес сме на живо“ — едно писмо на кръг, в прозореца преди старта на
 * състезанието. Тръгва от графика на всеки 15 мин; вътрешните пазачи го
 * пускат ефективно веднъж на състезателна неделя.
 *
 * Нарочно само за състезанието, не за всяка сесия: пет писма на уикенд са
 * спам, а неделният старт е моментът, в който би седнал да гледаш.
 */
class LiveCoverageAnnounceCommand extends Command
{
    protected $signature = 'f1:live-announce
        {--race= : ID на състезание (ръчен пуск, заобикаля прозореца)}
        {--force : Праща дори ако вече е пращано за този кръг}
        {--dry-run : Само отчита кой би получил писмо}';

    protected $description = 'Изпраща „днес сме на живо" преди старта на състезанието, с обяснение какво е live таймингът.';

    /** Часове преди старта, в които писмото има смисъл. */
    private const HOURS_BEFORE_START = 3;

    public function handle(NewsletterAudience $audience, OpenF1TokenManager $tokens): int
    {
        // Без включен модул или без OpenF1 креденшъли /live не показва нищо
        // по време на сесия — писмо, което кани към празна страница, е
        // по-лошо от липса на писмо.
        if (! config('features.live_timing')) {
            $this->info('FEATURE_LIVE_TIMING е изключен — пропускаме.');

            return self::SUCCESS;
        }

        if (! $tokens->hasCredentials()) {
            $this->warn('Няма OpenF1 креденшъли — /live би било празно по време на сесия. Пропускаме.');

            return self::SUCCESS;
        }

        $race = $this->resolveRace();

        if ($race === null) {
            $this->info('Няма старт в следващите '.self::HOURS_BEFORE_START.' часа — пропускаме.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->alreadySent($race)) {
            $this->info("Вече е пращано за кръг [{$race->id}] — пропускаме.");

            return self::SUCCESS;
        }

        $recipients = $audience->users();
        $subscribers = $audience->subscribersWithoutAccount($recipients);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$recipients->count()} потребители + {$subscribers->count()} абонати за „{$race->name_bg}“.");

            return self::SUCCESS;
        }

        // Маркираме ПРЕДИ пращането — дублиран пуск вижда записа и спира.
        NewsletterSend::create([
            'mail_type' => NewsletterSend::TYPE_LIVE_COVERAGE,
            'race_id' => $race->id,
            'sent_at' => now(),
        ]);

        $raceName = app(RaceNameLocalizer::class)->localize($race->jolpica_id, $race->name);
        $startAt = $race->race_datetime_utc
            ->copy()->setTimezone('Europe/Sofia')->format('H:i').' ч.';

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new LiveCoverageMail(
                $race,
                $raceName,
                $startAt,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        foreach ($subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new LiveCoverageMail(
                $race,
                $raceName,
                $startAt,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Писмото е в опашката: {$recipients->count()} потребители + {$subscribers->count()} абонати.");

        return self::SUCCESS;
    }

    private function resolveRace(): ?Race
    {
        if ($id = $this->option('race')) {
            return Race::query()->find($id);
        }

        $season = Season::current();

        if ($season === null) {
            return null;
        }

        return $season->races()
            ->whereNotNull('race_datetime_utc')
            ->whereBetween('race_datetime_utc', [now(), now()->addHours(self::HOURS_BEFORE_START)])
            ->orderBy('race_datetime_utc')
            ->first();
    }

    private function alreadySent(Race $race): bool
    {
        return NewsletterSend::query()
            ->where('mail_type', NewsletterSend::TYPE_LIVE_COVERAGE)
            ->where('race_id', $race->id)
            ->exists();
    }
}
