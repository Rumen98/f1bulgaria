<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsBulkMail;
use App\Mail\FeatureAnnouncementMail;
use App\Models\NewsletterSend;
use App\Models\RaceDataRecap;
use App\Models\Season;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Predictions\PredictionLockService;
use App\Services\Races\RaceNameLocalizer;
use Illuminate\Console\Command;
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
    use SendsBulkMail;

    protected $signature = 'padok:announce-features
        {--dry-run : Само отчита кой би получил писмо}
        {--force : Праща дори ако това съобщение вече е изпращано}';

    protected $description = 'Изпраща еднократното писмо с новостите (Данни и Инженерство) до всички.';

    /**
     * Уникален slug на тази вълна — държи идемпотентността в newsletter_sends.
     */
    private const MAIL_TYPE = 'announcement-2026-09-danni-inzhenerstvo';

    public function handle(NewsletterAudience $audience, PredictionLockService $locks): int
    {
        // Писмото сочи към /danni и /inzhenerstvo. При изключен флаг рутът
        // връща 404 — а разпратено писмо не се връща обратно.
        $off = collect(['data_recap' => 'Данни', 'engineering' => 'Инженерство'])
            ->reject(fn (string $label, string $flag) => (bool) config("features.{$flag}"));

        if ($off->isNotEmpty()) {
            $this->error('Изключени раздели: '.$off->implode(', ').'. Писмото щеше да води към 404 — не пращам.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->alreadySent()) {
            $this->info('Това съобщение вече е изпращано — пропускаме. (--force за повторно)');

            return self::SUCCESS;
        }

        $recipients = $audience->users();
        $subscribers = $audience->subscribersWithoutAccount($recipients);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");
            $this->line('Анализирани състезания в писмото: '.RaceDataRecap::query()->ready()->count());

            return self::SUCCESS;
        }

        // Маркираме ПРЕДИ пращането, както дайджестът: дублиран пуск вижда
        // записа и не праща втори път.
        NewsletterSend::create([
            'mail_type' => self::MAIL_TYPE,
            'sent_at' => now(),
        ]);

        $nextRace = $this->nextRace($locks);
        // Броят решава дали писмото изобщо да споменава архива — така не може
        // да обещае история, която още не е сметната.
        $analysed = RaceDataRecap::query()->ready()->count();

        foreach ($recipients as $user) {
            $this->sendMail($user, new FeatureAnnouncementMail(
                nextRace: $nextRace,
                analysedRaces: $analysed,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        foreach ($subscribers as $subscriber) {
            $this->sendMail($subscriber->email, new FeatureAnnouncementMail(
                nextRace: $nextRace,
                analysedRaces: $analysed,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Писмото е изпратено: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        $this->reportMailOutcome();

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
            'name' => app(RaceNameLocalizer::class)->forRace($race),
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
