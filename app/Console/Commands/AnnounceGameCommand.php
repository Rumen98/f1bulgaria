<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsBulkMail;
use App\Mail\GameAnnouncementMail;
use App\Models\NewsletterSend;
use App\Services\Game\LeaderboardService;
use App\Services\Game\WeekTrackResolver;
use App\Services\Newsletter\NewsletterAudience;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * Еднократното писмо за симулатора — пуска се РЪЧНО, няма график.
 *
 * Същият договор като padok:announce-features: идемпотентно през
 * newsletter_sends по MAIL_TYPE (повторен пуск не праща втори път),
 * --dry-run само брои, --force повтаря нарочно. Праща синхронно
 * (SendsBulkMail) — при мъртъв worker опашката би „изпратила" в нищото.
 */
class AnnounceGameCommand extends Command
{
    use SendsBulkMail;

    protected $signature = 'padok:announce-game
        {--dry-run : Само отчита кой би получил писмо}
        {--force : Праща дори ако това съобщение вече е изпращано}';

    protected $description = 'Изпраща еднократното писмо за симулатора до всички регистрирани и абонатите на бюлетина.';

    /**
     * Уникален slug на тази вълна — държи идемпотентността в newsletter_sends.
     */
    private const MAIL_TYPE = 'announcement-2026-09-game';

    public function handle(NewsletterAudience $audience, WeekTrackResolver $weekTrack, LeaderboardService $leaderboard): int
    {
        // Писмото води към /game. При изключен флаг рутът връща 404 — а
        // разпратено писмо не се връща обратно.
        if (! config('features.game')) {
            $this->error('Играта е изключена (FEATURE_GAME). Писмото щеше да води към 404 — не пращам.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->alreadySent()) {
            $this->info('Това съобщение вече е изпращано — пропускаме. (--force за повторно)');

            return self::SUCCESS;
        }

        $recipients = $audience->users();
        $subscribers = $audience->subscribersWithoutAccount($recipients);
        $trackCount = count((array) config('game.tracks', []));
        $week = $this->weekTrack($weekTrack, $leaderboard);

        // Писмото кани „просто отговори" — без Reply-To отговорите отиват към
        // novini@padok.bg, който няма пощенска кутия (виж config/mail.php).
        if (blank(config('mail.reply_to.address'))) {
            $this->warn('MAIL_REPLY_TO_ADDRESS не е зададен — отговорите на писмото ще се загубят.');
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");
            $this->line("Писти в писмото: {$trackCount}; писта на уикенда: ".($week['name'] ?? 'няма'));

            return self::SUCCESS;
        }

        // Маркираме ПРЕДИ пращането, както дайджестът: дублиран пуск вижда
        // записа и не праща втори път.
        NewsletterSend::create([
            'mail_type' => self::MAIL_TYPE,
            'sent_at' => now(),
        ]);

        foreach ($recipients as $user) {
            $this->sendMail($user, new GameAnnouncementMail(
                trackCount: $trackCount,
                weekTrack: $week,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        foreach ($subscribers as $subscriber) {
            $this->sendMail($subscriber->email, new GameAnnouncementMail(
                trackCount: $trackCount,
                weekTrack: $week,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Писмото е изпратено: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        $this->reportMailOutcome();

        return self::SUCCESS;
    }

    /**
     * Пистата на уикенда — където Ф1 кара тази седмица и където е седмичното
     * предизвикателство. CTA-то на писмото я отваря директно (/game?track=…).
     *
     * Резолверът връща най-близкото БЪДЕЩО състезание с писта в играта, дори
     * да е след три седмици (лятна пауза). Писмото казва „тази седмица",
     * затова важи само ако седмицата му (четвъртък преди старта) вече е
     * започнала; иначе null → писмото сочи към каталога.
     *
     * @return array{name: string, url: string}|null
     */
    private function weekTrack(WeekTrackResolver $resolver, LeaderboardService $leaderboard): ?array
    {
        $week = $resolver->resolve();

        if ($week === null || $week['week_start']->isFuture()) {
            return null;
        }

        $names = collect($leaderboard->trackIndex())->pluck('name', 'slug');

        return [
            'name' => (string) ($names[$week['slug']] ?? $week['slug']),
            'url' => route('game', ['track' => $week['slug']]),
        ];
    }

    private function alreadySent(): bool
    {
        return NewsletterSend::query()
            ->where('mail_type', self::MAIL_TYPE)
            ->exists();
    }
}
