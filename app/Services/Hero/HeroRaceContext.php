<?php

declare(strict_types=1);

namespace App\Services\Hero;

use App\Enums\HeroState;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Контекст за hero секцията — кое състезание да се покаже и докога броим.
 *
 * @property Collection<int, RaceSession> $sessions
 */
final readonly class HeroRaceContext
{
    /**
     * @param  Collection<int, RaceSession>  $sessions  предстоящи сесии за уикенда (само при active)
     */
    public function __construct(
        public HeroState $state,
        public ?Race $race,
        public ?string $circuitSlug,
        public ?CarbonImmutable $countdownTo,
        public string $countdownLabel,
        public ?RaceSession $nextSession,
        public Collection $sessions,
        public ?Driver $winner,
        /** Стартът е минал (по часовник, не по резултати). */
        public bool $raceStarted = false,
        /** Минали са 3 часа от старта — регламентният таван, значи сигурно е свършило. */
        public bool $raceFinished = false,
    ) {}
}
