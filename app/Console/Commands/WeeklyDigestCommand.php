<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsBulkMail;
use App\Mail\WeeklyDigestMail;
use App\Models\F2Driver;
use App\Models\F2Result;
use App\Models\F2Season;
use App\Models\NewsletterSend;
use App\Models\Race;
use App\Models\Season;
use App\Models\TeamNewsItem;
use App\Models\User;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Predictions\LeaderboardService;
use App\Services\Predictions\PredictionLockService;
use App\Services\Quiz\QuizProgressService;
use App\Support\DriverName;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

class WeeklyDigestCommand extends Command
{
    use SendsBulkMail;

    protected $signature = 'f1:weekly-digest
        {--race= : ID на състезание (по подразбиране — последното изминало; заобикаля 7-дневния прозорец за ръчен re-send)}
        {--any-hour : Праща и извън приличния часови прозорец (за ръчно пускане)}';

    protected $description = 'Изпраща неделен рекап на състезанието + класиране на prediction league-а.';

    /**
     * Прозорец за съдържанието на секциите (Ф2, новини, значки) — по една
     * седмица назад, колкото покрива един неделен пуск.
     */
    private const FRESHNESS_DAYS = 7;

    /**
     * Колко назад търсим неизпратено състезание. По-широк от седмица
     * нарочно: кръг, чиито резултати са закъснели, се наваксва по-късно.
     * Дедупликацията е в `newsletter_sends`, не в прозореца.
     */
    private const WINDOW_DAYS = 14;

    /**
     * Приличен час за писмо (софийско време), включително границите.
     *
     * Командата върви ЕЖЕЧАСНО и праща в мига, в който резултатите се
     * появят — Jolpica публикува когато си иска и фиксираният неделен час
     * в 20:00 беше единствен изстрел: пропуснеше ли го, рекапът чакаше
     * цяла седмица и рискуваше да бъде изяден от следващия кръг (при
     * подредба по най-нов). Единственото, което пазим от денонощието, е
     * писмо в 3 през нощта.
     */
    private const EARLIEST_HOUR = 9;

    private const LATEST_HOUR = 22;

    /** Slug на Цолов в `f2_drivers` — Ф2 секцията следи неговия уикенд. */
    private const TSOLOV_SLUG = 'nikola-tsolov';

    /** Съкратени имена на дните, индекс = Carbon dayOfWeek (0 = неделя). */
    private const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

    public function handle(
        LeaderboardService $leaderboard,
        NewsletterAudience $audience,
        PredictionLockService $locks,
        QuizProgressService $quizProgress,
    ): int {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        // Ежечасният пуск не бива да буди хората. Ръчното пускане и изричният
        // --race заобикалят прозореца — тогава решението е на човек.
        if (! $this->withinCivilHours()) {
            $this->line('Извън приличния часови прозорец ('
                .self::EARLIEST_HOUR.':00-'.self::LATEST_HOUR.':00) — изчакваме.');

            return self::SUCCESS;
        }

        $race = $this->resolveRace($season);

        // Спринт резултатите не са достатъчни: whereHas('results') без филтър
        // би пратил „рекап" по средата на неделното състезание при спринтов
        // уикенд (съботните редове вече са в базата).
        if ($race === null || ! $race->results()->where('session_type', 'race')->exists()) {
            $this->warn('Няма ново състезание с резултати от неделното състезание — пропускаме.');

            return self::SUCCESS;
        }

        if ($race->season_id !== $season->id) {
            $this->warn('Състезанието не е от текущия сезон — рекапът би смесил данни от два сезона.');

            return self::SUCCESS;
        }

        // Маркираме ПРЕДИ пращането: дублиран cron или повторен пуск вижда
        // записа и не праща втори път.
        NewsletterSend::create([
            'mail_type' => NewsletterSend::TYPE_DIGEST,
            'race_id' => $race->id,
            'sent_at' => now(),
        ]);

        $recap = $this->buildRecap($race);
        $fullBoard = $leaderboard->forSeason($season);
        $board = $fullBoard->take(10)->values()->all();
        $f2 = $this->buildF2Recap();
        $news = $this->buildTopNews();
        $nextRace = $this->buildNextRace($race, $locks);

        $recipients = $audience->users();

        foreach ($recipients as $user) {
            $stats = $this->decorateStats($leaderboard->userStats($user, $season), $user, $fullBoard);

            $this->sendMail($user, new WeeklyDigestMail(
                $race,
                $recap,
                $board,
                $stats,
                f2: $f2,
                news: $news,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
                nextRace: $nextRace,
                quiz: $this->quizProgress($quizProgress, $user),
            ));
        }

        // Бюлетинните абонати без акаунт получават общата версия
        // (без лична статистика) с линк за отписване. Дедупликация по имейл.
        $subscribers = $audience->subscribersWithoutAccount($recipients);

        foreach ($subscribers as $subscriber) {
            $this->sendMail($subscriber->email, new WeeklyDigestMail(
                $race,
                $recap,
                $board,
                userStats: null,
                unsubscribeToken: $subscriber->unsubscribe_token,
                f2: $f2,
                news: $news,
                nextRace: $nextRace,
            ));
        }

        $this->info("Дайджестът е изпратен: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        $this->reportMailOutcome();

        return self::SUCCESS;
    }

    /**
     * Следващият кръг след рекапирания. Неделя 20:00 е върхът на вниманието
     * за седмицата — без този блок писмото приключва с класиране, а поканата
     * за следващата прогноза идва чак в петъчното preview, когато интересът
     * вече е спаднал.
     *
     * @return array{name:string, url:string, deadline:string|null}|null
     */
    private function buildNextRace(Race $race, PredictionLockService $locks): ?array
    {
        $next = Race::query()
            ->where('season_id', $race->season_id)
            ->where('race_datetime_utc', '>', $race->race_datetime_utc)
            ->orderBy('race_datetime_utc')
            ->first();

        if ($next === null) {
            return null;
        }

        $deadline = $locks->lockDeadline($next);

        return [
            'name' => $next->name_bg,
            'url' => route('races.show', $next),
            // Изминал срок не се показва: спринтов уикенд може да заключи
            // прогнозите преди неделния пуск на следващия рекап.
            'deadline' => $deadline?->isFuture() ? $this->sofia($deadline) : null,
        ];
    }

    private function sofia(CarbonInterface $utc): string
    {
        $sofia = $utc->copy()->setTimezone('Europe/Sofia');

        return self::WEEKDAYS[(int) $sofia->dayOfWeek].', '.$sofia->format('d.m').' — '.$sofia->format('H:i').' ч.';
    }

    /**
     * Часът в София е приличен за писмо.
     *
     * Резултатите на Jolpica идват когато си искат, включително посред нощ.
     * Пращането е закачено за появата им, не за фиксиран час — прозорецът е
     * единственото, което пази получателя от известие в 3 сутринта.
     */
    private function withinCivilHours(): bool
    {
        if ($this->option('any-hour') || $this->option('race')) {
            return true;
        }

        $hour = (int) Carbon::now('Europe/Sofia')->hour;

        return $hour >= self::EARLIEST_HOUR && $hour <= self::LATEST_HOUR;
    }

    private function resolveRace(Season $season): ?Race
    {
        if ($id = $this->option('race')) {
            return Race::query()->find($id);
        }

        $alreadySent = NewsletterSend::query()
            ->where('mail_type', NewsletterSend::TYPE_DIGEST)
            ->whereNotNull('race_id')
            ->select('race_id');

        return $season->races()
            ->whereHas('results', fn ($q) => $q->where('session_type', 'race'))
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotIn('id', $alreadySent)
            ->orderByDesc('race_datetime_utc')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecap(Race $race): array
    {
        return $race->results()
            ->where('session_type', 'race')
            ->with('driver')
            ->whereNotNull('position')
            ->whereBetween('position', [1, 3])
            ->orderBy('position')
            ->get()
            ->map(fn ($result) => [
                'position' => $result->position,
                'driver' => $result->driver
                    ? DriverName::display($result->driver->slug, $result->driver->fullName())
                    : null,
                'fastest_lap' => $result->fastest_lap,
            ])
            ->all();
    }

    /**
     * Лична позиция в лигата + спечелените през седмицата значки, върху
     * статистиката от LeaderboardService.
     *
     * @param  array{points:int, predictions:int, best:int, average:float}  $stats
     * @param  Collection<int, array{position:int, user:User, points:int, predictions:int}>  $board
     * @return array<string, mixed>
     */
    private function decorateStats(array $stats, User $user, Collection $board): array
    {
        $entry = $board->first(fn (array $row) => $row['user']->id === $user->id);

        $stats['rank'] = $entry['position'] ?? null;
        $stats['players'] = $board->count();
        $stats['new_badges'] = $user->badges()
            ->wherePivot('awarded_at', '>=', now()->subDays(self::FRESHNESS_DAYS))
            ->pluck('name')
            ->all();

        return $stats;
    }

    /**
     * Прогресът в куиза за секцията в дайджеста.
     *
     * Куизът се подсеща оттук, а не с отделно писмо: при този мащаб всяко
     * ново масово писмо е по-скоро причина за отписване, отколкото за
     * връщане. Само точките — броят въпроси в базата е подвижна цел.
     *
     * @return array{points:int}|null
     */
    private function quizProgress(QuizProgressService $progress, User $user): ?array
    {
        $stats = $progress->statsFor($user);

        if ($stats['available'] === 0) {
            return null; // няма въпроси в базата — няма какво да рекламираме
        }

        return ['points' => $stats['points']];
    }

    /**
     * Ф2 уикендът на Цолов, ако през последната седмица е имало кръг с
     * негови резултати. null скрива секцията.
     *
     * @return array<string, mixed>|null
     */
    private function buildF2Recap(): ?array
    {
        $season = F2Season::current();

        if ($season === null) {
            return null;
        }

        $tsolov = F2Driver::query()
            ->where('f2_season_id', $season->id)
            ->where('slug', self::TSOLOV_SLUG)
            ->first();

        if ($tsolov === null) {
            return null;
        }

        $race = $season->races()
            ->whereNotNull('race_datetime_utc')
            ->whereBetween('race_datetime_utc', [now()->subDays(self::FRESHNESS_DAYS), now()])
            ->orderByDesc('race_datetime_utc')
            ->first();

        if ($race === null) {
            return null;
        }

        $results = F2Result::query()
            ->where('f2_driver_id', $tsolov->id)
            ->whereHas('session', fn ($q) => $q
                ->where('f2_race_id', $race->id)
                ->whereIn('session_type', ['sprint_race', 'feature_race']))
            ->with('session')
            ->get()
            ->sortBy(fn (F2Result $result) => $result->session->session_type->order())
            ->values()
            ->map(fn (F2Result $result) => [
                'session' => $result->session->session_type->label(),
                'position' => $result->position,
                'status' => $result->status,
            ])
            ->all();

        if ($results === []) {
            return null;
        }

        return [
            'race' => $race->country_name !== null
                ? "{$race->location_name}, {$race->country_name}"
                : $race->location_name,
            'results' => $results,
            'standings_position' => $tsolov->position,
            'points' => (float) $tsolov->points,
        ];
    }

    /**
     * Топ 3 новини от последната седмица за секцията в дайджеста.
     *
     * @return array<int, array{title:string, url:string}>
     */
    private function buildTopNews(): array
    {
        return TeamNewsItem::query()
            ->inMainFeed()
            ->where('published_at', '>=', now()->subDays(self::FRESHNESS_DAYS))
            ->orderByDesc('importance_score')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (TeamNewsItem $item) => [
                'title' => $item->title_bg ?: $item->title_original,
                'url' => route('news.show', $item->slug),
            ])
            ->all();
    }
}
