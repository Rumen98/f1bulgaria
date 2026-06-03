<?php

declare(strict_types=1);

namespace App\Services\Hero;

use App\Enums\HeroState;
use App\Enums\SessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use Carbon\CarbonImmutable;

/**
 * Определя кое Гран при да се покаже в hero секцията:
 * active weekend → предстоящо състезание → извън сезона.
 */
class NextRaceResolver
{
    /** Уикендът става „active" едва когато следваща сесия е в този прозорец (чет. вечер → нед.). */
    private const WEEKEND_LOOKAHEAD_HOURS = 36;

    /**
     * Колко часа след старта на състезанието още показваме post-race интерфейса.
     * ~5ч покрива ~2-часово състезание + ~3ч след финала.
     */
    private const POST_RACE_HOURS = 5;

    public function resolve(): HeroRaceContext
    {
        $now = CarbonImmutable::now();

        if ($race = $this->activeWeekendRace($now)) {
            return $this->activeContext($race, $now);
        }

        if ($race = $this->nextUpcomingRace($now)) {
            return new HeroRaceContext(
                state: HeroState::Upcoming,
                race: $race,
                circuitSlug: $race->jolpica_id,
                countdownTo: CarbonImmutable::parse($race->race_datetime_utc),
                countdownLabel: 'До състезанието',
                nextSession: null,
                sessions: collect(),
                winner: null,
            );
        }

        return new HeroRaceContext(
            state: HeroState::OffSeason,
            race: null,
            circuitSlug: null,
            countdownTo: $this->firstRaceNextSeason($now)?->race_datetime_utc
                ? CarbonImmutable::parse($this->firstRaceNextSeason($now)->race_datetime_utc)
                : null,
            countdownLabel: 'Сезонът приключи',
            nextSession: null,
            sessions: collect(),
            winner: null,
        );
    }

    /**
     * Уикендът е „active" само ако:
     *  - следваща сесия предстои в близките 36 часа (чет. вечер → нед. вечер), ИЛИ
     *  - състезанието е стартирало в последните ~5ч (post-race интерфейс).
     * Иначе → null (минаваме към upcoming).
     */
    private function activeWeekendRace(CarbonImmutable $now): ?Race
    {
        $upcomingRaceId = RaceSession::query()
            ->whereBetween('scheduled_at_utc', [$now, $now->addHours(self::WEEKEND_LOOKAHEAD_HOURS)])
            ->orderBy('scheduled_at_utc')
            ->value('race_id');

        if ($upcomingRaceId !== null) {
            return Race::find($upcomingRaceId);
        }

        $recentRaceId = RaceSession::query()
            ->where('type', SessionType::Race->value)
            ->whereBetween('scheduled_at_utc', [$now->subHours(self::POST_RACE_HOURS), $now])
            ->orderByDesc('scheduled_at_utc')
            ->value('race_id');

        return $recentRaceId !== null ? Race::find($recentRaceId) : null;
    }

    private function activeContext(Race $race, CarbonImmutable $now): HeroRaceContext
    {
        $upcoming = $race->sessions()
            ->where('scheduled_at_utc', '>', $now)
            ->orderBy('scheduled_at_utc')
            ->get();

        $next = $upcoming->first();

        return new HeroRaceContext(
            state: HeroState::Active,
            race: $race,
            circuitSlug: $race->jolpica_id,
            countdownTo: $next ? CarbonImmutable::parse($next->scheduled_at_utc) : null,
            countdownLabel: $next ? $this->sessionLabel($next->type) : 'Уикендът е в ход',
            nextSession: $next,
            sessions: $upcoming,
            winner: $this->winnerFor($race),
        );
    }

    private function nextUpcomingRace(CarbonImmutable $now): ?Race
    {
        return Race::query()
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '>', $now)
            ->orderBy('race_datetime_utc')
            ->first();
    }

    private function firstRaceNextSeason(CarbonImmutable $now): ?Race
    {
        // Най-ранното състезание изобщо в бъдещето (ако календарът за догодина е зареден).
        return Race::query()
            ->whereNotNull('race_datetime_utc')
            ->where('race_datetime_utc', '>', $now)
            ->orderBy('race_datetime_utc')
            ->first();
    }

    private function winnerFor(Race $race): ?Driver
    {
        return $race->results()
            ->where('session_type', 'race')
            ->where('position', 1)
            ->with('driver')
            ->first()?->driver;
    }

    private function sessionLabel(SessionType $type): string
    {
        return match ($type) {
            SessionType::FP1 => 'До FP1',
            SessionType::FP2 => 'До FP2',
            SessionType::FP3 => 'До FP3',
            SessionType::Qualifying => 'До квалификацията',
            SessionType::SprintQuali => 'До спринт квалификацията',
            SessionType::Sprint => 'До спринта',
            SessionType::Race => 'До състезанието',
        };
    }
}
