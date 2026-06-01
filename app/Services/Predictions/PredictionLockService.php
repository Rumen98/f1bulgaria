<?php

declare(strict_types=1);

namespace App\Services\Predictions;

use App\Models\Race;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Заключва прогнозите N минути преди началото на квалификацията. Източник на
 * истината за "заключено ли е" е времето на квалификацията, не самото поле
 * locked_at (което само маркира вече стамповани прогнози).
 */
class PredictionLockService
{
    private int $lockMinutes;

    public function __construct()
    {
        $this->lockMinutes = (int) config('predictions.lock_minutes_before_qualifying', 5);
    }

    /**
     * Крайният момент за подаване на прогноза за дадено състезание.
     */
    public function lockDeadline(Race $race): ?CarbonImmutable
    {
        if ($race->qualifying_datetime_utc === null) {
            return null;
        }

        return CarbonImmutable::parse($race->qualifying_datetime_utc)
            ->subMinutes($this->lockMinutes);
    }

    /**
     * Заключено ли е състезанието за нови/редакции на прогнози в момента.
     */
    public function isLocked(Race $race): bool
    {
        $deadline = $this->lockDeadline($race);

        // Без обявено време на квалификация прогнозите остават отворени.
        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * Стампова locked_at на всички още незаключени прогнози за състезания, чийто
     * краен срок е минал. Връща броя заключени прогнози.
     */
    public function lockDue(): int
    {
        $races = Race::query()
            ->whereNotNull('qualifying_datetime_utc')
            ->where('qualifying_datetime_utc', '<=', now()->addMinutes($this->lockMinutes))
            ->whereHas('predictions', fn ($q) => $q->whereNull('locked_at'))
            ->get();

        $locked = 0;
        $now = Carbon::now();

        foreach ($races as $race) {
            if (! $this->isLocked($race)) {
                continue;
            }

            $locked += $race->predictions()
                ->whereNull('locked_at')
                ->update(['locked_at' => $now]);
        }

        return $locked;
    }
}
