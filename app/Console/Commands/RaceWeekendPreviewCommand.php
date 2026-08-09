<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\RaceWeekendPreviewMail;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Predictions\PredictionLockService;
use App\Services\Standings\StandingsService;
use App\Support\DriverName;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RaceWeekendPreviewCommand extends Command
{
    protected $signature = 'f1:race-preview {--race= : ID на състезание (заобикаля 7-дневния прозорец за ръчен пуск)}';

    protected $description = 'Изпраща петъчен preview на състезателния уикенд: програма в софийско време, залог в класирането и CTA за прогноза.';

    /**
     * Изпраща се само ако до толкова дни напред има състезание — иначе
     * петъчният график би пращал и в седмици без кръг.
     */
    private const LOOKAHEAD_DAYS = 7;

    /** Съкратени имена на дните, индекс = Carbon dayOfWeek (0 = неделя). */
    private const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

    public function handle(NewsletterAudience $audience, StandingsService $standings, PredictionLockService $locks): int
    {
        $season = Season::current();

        if ($season === null) {
            $this->warn('Няма текущ сезон.');

            return self::SUCCESS;
        }

        $race = $this->resolveRace($season);

        if ($race === null) {
            $this->warn('Няма състезание през следващите '.self::LOOKAHEAD_DAYS.' дни — пропускаме.');

            return self::SUCCESS;
        }

        $program = $this->buildProgram($race);
        $stake = $this->buildStake($standings->drivers($season)->take(2)->values());

        $deadline = $locks->lockDeadline($race);
        $deadlineText = $deadline !== null && $deadline->isFuture() ? $this->sofia($deadline) : null;

        $recipients = $audience->users();

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new RaceWeekendPreviewMail(
                $race,
                $program,
                $stake,
                $deadlineText,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $user->id]),
            ));
        }

        $subscribers = $audience->subscribersWithoutAccount($recipients);

        foreach ($subscribers as $subscriber) {
            Mail::to($subscriber->email)->queue(new RaceWeekendPreviewMail(
                $race,
                $program,
                $stake,
                $deadlineText,
                unsubscribeToken: $subscriber->unsubscribe_token,
            ));
        }

        $this->info("Preview-то е поставено в опашката: {$recipients->count()} потребители + {$subscribers->count()} бюлетинни абонати.");

        return self::SUCCESS;
    }

    private function resolveRace(Season $season): ?Race
    {
        if ($id = $this->option('race')) {
            return Race::query()->find($id);
        }

        return $season->races()
            ->whereNotNull('race_datetime_utc')
            ->whereBetween('race_datetime_utc', [now(), now()->addDays(self::LOOKAHEAD_DAYS)])
            ->orderBy('race_datetime_utc')
            ->first();
    }

    /**
     * Програмата на уикенда в софийско време — само предстоящите сесии
     * (петъчна сутрешна тренировка в Азия вече е минала към 09:00 у нас).
     * Пада към часовете от самото състезание, ако сесиите още не са
     * синхронизирани — включително спринта при спринтов уикенд.
     *
     * @return array<int, array{label:string, when:string}>
     */
    private function buildProgram(Race $race): array
    {
        $sessions = $race->sessions()
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '>', now())
            ->get()
            ->sortBy(fn (RaceSession $session) => $session->scheduled_at_utc->getTimestamp())
            ->values();

        if ($sessions->isNotEmpty()) {
            return $sessions
                ->map(fn (RaceSession $session) => [
                    'label' => $session->type->label(),
                    'when' => $this->sofia($session->scheduled_at_utc),
                ])
                ->all();
        }

        return collect([
            ['label' => 'Квалификация', 'at' => $race->qualifying_datetime_utc],
            ['label' => 'Спринт', 'at' => $race->has_sprint ? $race->sprint_datetime_utc : null],
            ['label' => 'Състезание', 'at' => $race->race_datetime_utc],
        ])
            ->filter(fn (array $row) => $row['at'] !== null && $row['at']->isFuture())
            ->sortBy(fn (array $row) => $row['at']->getTimestamp())
            ->map(fn (array $row) => ['label' => $row['label'], 'when' => $this->sofia($row['at'])])
            ->values()
            ->all();
    }

    /**
     * Едноредов залог: кой води в шампионата и с колко. null при празно
     * класиране (началото на сезона).
     *
     * @param  Collection<int, array{position:int, driver:Driver|null, points:float, wins:int}>  $top
     */
    private function buildStake(Collection $top): ?string
    {
        if ($top->count() < 2 || $top[0]['driver'] === null || $top[1]['driver'] === null) {
            return null;
        }

        $leader = DriverName::display($top[0]['driver']->slug, $top[0]['driver']->fullName());
        $second = DriverName::display($top[1]['driver']->slug, $top[1]['driver']->fullName());
        $gap = $top[0]['points'] - $top[1]['points'];
        $gapText = $gap == (int) $gap ? (string) (int) $gap : (string) $gap;

        if ($gap == 0.0) {
            return "{$leader} и {$second} са с равни точки на върха на класирането.";
        }

        return "{$leader} води в шампионата с {$gapText} т. аванс пред {$second}.";
    }

    private function sofia(CarbonInterface $utc): string
    {
        $sofia = $utc->copy()->setTimezone('Europe/Sofia');

        return self::WEEKDAYS[(int) $sofia->dayOfWeek].', '.$sofia->format('d.m').' — '.$sofia->format('H:i').' ч.';
    }
}
