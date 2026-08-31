<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\FeatureAnnouncementMail;
use App\Models\NewsletterSend;
use App\Models\Season;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Predictions\PredictionLockService;
use App\Services\Races\RaceNameLocalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Еднократното писмо с новостите — пуска се РЪЧНО, няма график.
 *
 * Идемпотентно през newsletter_sends по MAIL_TYPE: повторен пуск (или
 * дублирана команда от два терминала) не праща втори път. При следваща
 * вълна новости се сменя slug-ът в MAIL_TYPE — не се преизползва.
 */
class AnnounceFeaturesCommand extends Command
{
    protected $signature = 'padok:announce-features
        {--dry-run : Само отчита кой би получил писмо}
        {--force : Праща дори ако това съобщение вече е изпращано}';

    protected $description = 'Изпраща еднократното писмо с новостите (значки, куиз точки, предстоящи награди) до всички.';

    /**
     * Уникален slug на тази вълна — държи идемпотентността в newsletter_sends.
     */
    private const MAIL_TYPE = 'announcement-2026-09-features';

    public function handle(NewsletterAudience $audience, PredictionLockService $locks): int
    {
        if (! $this->option('force') && $this->alreadySent()) {
            $this->info('Това съобщение вече е изпращано — пропускаме. (--force за повторно)');

            return self::SUCCESS;
        }

        $recipients = $audience->users();
        $subscribers = $audience->subscribersWithoutAccount($recipients);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

            return self::SUCCESS;
        }

        // Маркираме ПРЕДИ пращането, както дайджестът: дублиран пуск вижда
        // записа и не праща втори път.
        NewsletterSend::create([
            'mail_type' => self::MAIL_TYPE,
            'sent_at' => now(),
        ]);

        $nextRace = $this->nextRace($locks);

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new FeatureAnnouncementMail(
                nextRace: $nextRace,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        foreach ($subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new FeatureAnnouncementMail(
                nextRace: $nextRace,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Писмото е в опашката: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        return self::SUCCESS;
    }

    /**
     * Следващият кръг с още отворени прогнози — CTA-то на писмото. null при
     * липса (краесезонна пауза) → писмото сочи към класирането.
     *
     * @return array{name:string, url:string, deadline:?string}|null
     */
    private function nextRace(PredictionLockService $locks): ?array
    {
        $season = Season::current();

        if ($season === null) {
            return null;
        }

        $race = $season->races()
            ->whereNotNull('qualifying_datetime_utc')
            ->where('qualifying_datetime_utc', '>', now())
            ->orderBy('qualifying_datetime_utc')
            ->first();

        if ($race === null) {
            return null;
        }

        $deadline = $locks->lockDeadline($race);

        if ($deadline === null || $deadline->isPast()) {
            return null;
        }

        return [
            'name' => app(RaceNameLocalizer::class)->localize($race->jolpica_id, $race->name),
            'url' => route('races.show', $race->id),
            'deadline' => $deadline->setTimezone('Europe/Sofia')->format('d.m.Y, H:i').' ч.',
        ];
    }

    private function alreadySent(): bool
    {
        return NewsletterSend::query()
            ->where('mail_type', self::MAIL_TYPE)
            ->exists();
    }
}
