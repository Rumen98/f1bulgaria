<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use App\Enums\SessionType;
use App\Models\RaceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * „Наистина ли тече сесия в момента" — по разписанието в базата, без外 външни
 * заявки. Разписанието се синхронизира от Jolpica на 15 мин и е достатъчно
 * за навигацията; реалните live данни (OpenF1) остават работа на /live.
 *
 * Сесиите нямат край в схемата, затова прозорецът е [старт − буфер,
 * старт + типова продължителност]. Буферът хваща загрявката, а щедрата
 * продължителност — червени флагове и забавени стартове: по-добре линкът
 * да светне 10 минути по-рано и да угасне половин час по-късно, отколкото
 * да изчезне по средата на прекъснато състезание.
 */
class LiveWindowService
{
    /** Минути преди старта, в които вече се брои за „на живо“. */
    private const PRE_START_MINUTES = 10;

    /** Кеш от една минута: една заявка на минута, не на всяка страница. */
    private const CACHE_SECONDS = 60;

    public function isLiveNow(): bool
    {
        return $this->currentSession() !== null;
    }

    /**
     * Текущата сесия по разписание, или null извън прозорец.
     */
    public function currentSession(): ?RaceSession
    {
        return Cache::remember('live-window:current', self::CACHE_SECONDS, function (): ?RaceSession {
            $now = Carbon::now();

            // Грубият SQL прозорец (най-дългата възможна сесия назад) държи
            // заявката индексируема; точната проверка по тип е в PHP.
            return RaceSession::query()
                ->whereNotNull('scheduled_at_utc')
                ->whereBetween('scheduled_at_utc', [
                    $now->copy()->subMinutes($this->longestDurationMinutes()),
                    $now->copy()->addMinutes(self::PRE_START_MINUTES),
                ])
                ->orderByDesc('scheduled_at_utc')
                ->get()
                ->first(fn (RaceSession $session) => $this->covers($session, $now));
        }) ?: null;
    }

    private function covers(RaceSession $session, Carbon $now): bool
    {
        $start = $session->scheduled_at_utc->copy()->subMinutes(self::PRE_START_MINUTES);
        $end = $session->scheduled_at_utc->copy()->addMinutes($this->durationMinutes($session->type));

        return $now->betweenIncluded($start, $end);
    }

    /**
     * Колко трае една сесия, с резерв за прекъсвания.
     */
    private function durationMinutes(SessionType $type): int
    {
        return match ($type) {
            // 2 часа лимит на чисто каране + червени флагове (правилото за
            // 3 часа общо време на събитието).
            SessionType::Race => 180,
            SessionType::FP1, SessionType::FP2, SessionType::FP3 => 90,
            SessionType::Qualifying, SessionType::SprintQuali => 90,
            SessionType::Sprint => 90,
        };
    }

    private function longestDurationMinutes(): int
    {
        return 180;
    }
}
