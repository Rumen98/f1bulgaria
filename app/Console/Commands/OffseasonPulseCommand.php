<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsBulkMail;
use App\Mail\OffseasonPulseMail;
use App\Models\NewsletterSend;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Standings\StandingsService;
use App\Support\DriverName;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class OffseasonPulseCommand extends Command
{
    use SendsBulkMail;

    protected $signature = 'f1:offseason-pulse {--force : Пропуска guard-овете за ръчен пуск}';

    protected $description = 'Месечен бюлетин през паузите: топ новини от периода + отброяване до следващия кръг.';

    /**
     * Пулсът излиза само когато другите имейли мълчат: без състезание
     * QUIET_DAYS назад (иначе неделният дайджест покрива периода) и без
     * предстоящо в следващите UPCOMING_BUFFER_DAYS. Буферът е 10, а не 7:
     * командата върви в сряда, а петъчното preview гледа 7 дни напред от
     * петък — при 7 пулс и preview биха се застъпили в една седмица.
     */
    private const QUIET_DAYS = 14;

    private const UPCOMING_BUFFER_DAYS = 10;

    /**
     * Минимум дни между два пулса — графикът е седмичен (за да улучва и
     * лятната пауза), но повече от един пулс месечно е спам.
     */
    private const COOLDOWN_DAYS = 21;

    private const NEWS_DAYS = 30;

    /** Съкратени имена на дните, индекс = Carbon dayOfWeek (0 = неделя). */
    private const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

    public function handle(NewsletterAudience $audience, StandingsService $standings): int
    {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        $lastRace = Race::query()
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '<=', now())
            ->orderByDesc('race_datetime_utc')
            ->first();

        $nextRace = Race::query()
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '>', now())
            ->orderBy('race_datetime_utc')
            ->first();

        if (! $this->option('force')) {
            if ($lastRace?->race_datetime_utc?->greaterThan(now()->subDays(self::QUIET_DAYS))) {
                $this->warn('Има състезание през последните '.self::QUIET_DAYS.' дни — неделният дайджест покрива периода, пропускаме.');

                return self::SUCCESS;
            }

            if ($nextRace?->race_datetime_utc?->lessThan(now()->addDays(self::UPCOMING_BUFFER_DAYS))) {
                $this->warn('Следващият кръг е до '.self::UPCOMING_BUFFER_DAYS.' дни — петъчното preview поема, пропускаме.');

                return self::SUCCESS;
            }

            $recentPulse = NewsletterSend::query()
                ->where('mail_type', NewsletterSend::TYPE_PULSE)
                ->where('sent_at', '>=', now()->subDays(self::COOLDOWN_DAYS))
                ->exists();

            if ($recentPulse) {
                $this->warn('Пулс е пращан през последните '.self::COOLDOWN_DAYS.' дни — пропускаме.');

                return self::SUCCESS;
            }
        }

        $news = $this->buildTopNews();
        $countdown = $nextRace !== null ? $this->buildCountdown($nextRace) : null;

        if ($news === [] && $countdown === null) {
            $this->warn('Няма нито новини, нито обявен следващ кръг — няма какво да пратим.');

            return self::SUCCESS;
        }

        $topDrivers = $standings->drivers($season)
            ->take(3)
            ->values()
            ->filter(fn (array $row) => $row['driver'] !== null)
            ->map(fn (array $row) => [
                'position' => $row['position'],
                'driver' => DriverName::display($row['driver']->slug, $row['driver']->fullName()),
                'points' => $row['points'],
            ])
            ->all();

        // Маркираме ПРЕДИ пращането: дублиран cron или повторен пуск вижда
        // записа и не праща втори път.
        NewsletterSend::create([
            'mail_type' => NewsletterSend::TYPE_PULSE,
            'sent_at' => now(),
        ]);

        $recipients = $audience->users();

        foreach ($recipients as $user) {
            $this->sendMail($user, new OffseasonPulseMail(
                $news,
                $countdown,
                $topDrivers,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        $subscribers = $audience->subscribersWithoutAccount($recipients);

        foreach ($subscribers as $subscriber) {
            $this->sendMail($subscriber->email, new OffseasonPulseMail(
                $news,
                $countdown,
                $topDrivers,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Пулсът е изпратен: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        $this->reportMailOutcome();

        return self::SUCCESS;
    }

    /**
     * Топ 5 новини от последния месец.
     *
     * @return array<int, array{title:string, url:string}>
     */
    private function buildTopNews(): array
    {
        return TeamNewsItem::query()
            ->inMainFeed()
            ->where('published_at', '>=', now()->subDays(self::NEWS_DAYS))
            ->orderByDesc('importance_score')
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn (TeamNewsItem $item) => [
                'title' => $item->title_bg ?: $item->title_original,
                'url' => route('news.show', $item->slug),
            ])
            ->all();
    }

    /**
     * @return array{race:string, when:string, days:int}
     */
    private function buildCountdown(Race $race): array
    {
        return [
            'race' => $race->name_bg,
            'when' => $this->sofia($race->race_datetime_utc),
            'days' => (int) ceil(now()->diffInDays($race->race_datetime_utc, true)),
        ];
    }

    private function sofia(CarbonInterface $utc): string
    {
        $sofia = $utc->copy()->setTimezone('Europe/Sofia');

        return self::WEEKDAYS[(int) $sofia->dayOfWeek].', '.$sofia->format('d.m').' — '.$sofia->format('H:i').' ч.';
    }
}
