<?php

declare(strict_types=1);

namespace App\Services\Hero;

use App\Models\Driver;
use App\Models\Race;
use App\Services\LiveTiming\OpenF1Client;
use App\Services\LiveTiming\OpenF1TokenManager;
use App\Support\DriverName;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Победителят от OpenF1, когато Jolpica още мълчи.
 *
 * ЗАЩО: Jolpica е авторитетна, но публикува с часове закъснение. Дотогава
 * hero-то няма победител и не може да покаже нищо смислено за току-що
 * изкарано състезание. OpenF1 дава класирането минути след финала.
 *
 * ГРАНИЦА НА ОТГОВОРНОСТТА: това е САМО за показване в hero-то. Нищо тук не
 * пише в `results` и нищо не стартира точкуване. Причината е важна:
 * класирането веднага след финала е временно — стюардите раздават наказания
 * след това. Точкуване по него значеше хората да видят точки, които после
 * се променят. Класация, която мърда назад, е по-лоша от класация, която
 * закъснява. Jolpica остава единственият източник за `results` и точките.
 */
class PostRaceWinnerResolver
{
    public function __construct(
        private readonly OpenF1Client $client,
        private readonly OpenF1TokenManager $tokens,
    ) {}

    /**
     * Име на победителя за показване, или null.
     *
     * Никога не хвърля: това върви в рендер пътя на началната страница и
     * авария в OpenF1 не бива да я поваля. При провал просто няма победител
     * и часовникът поема — hero-то пак казва истината, само без име.
     */
    public function displayName(Race $race): ?string
    {
        if (! config('features.live_timing') || ! $this->tokens->hasCredentials()) {
            return null;
        }

        try {
            $sessionKey = $this->raceSessionKey($race);

            if ($sessionKey === null) {
                return null;
            }

            $winner = $this->client->getSessionResult($sessionKey)
                ->first(fn (array $row) => (int) ($row['position'] ?? 0) === 1);

            $number = $winner['driver_number'] ?? null;

            if ($number === null) {
                return null;
            }

            $driver = Driver::query()->where('permanent_number', (int) $number)->first();

            return $driver !== null
                ? DriverName::display($driver->slug, $driver->fullName())
                : null;
        } catch (Throwable $e) {
            Log::warning('OpenF1 победител за hero-то не можа да се вземе: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Ключът на състезателната сесия в OpenF1 за този кръг.
     *
     * Съпоставя се по дата, а не по име: имената на кръговете се различават
     * между източниците („Italian Grand Prix" срещу „Italy").
     */
    private function raceSessionKey(Race $race): ?int
    {
        if ($race->race_datetime_utc === null) {
            return null;
        }

        $session = $this->client->getSeasonSessions((int) $race->race_datetime_utc->year)
            ->first(function (array $s) use ($race): bool {
                if (($s['session_type'] ?? null) !== 'Race' || ! isset($s['date_start'])) {
                    return false;
                }

                return abs(strtotime((string) $s['date_start']) - $race->race_datetime_utc->timestamp) < 7200;
            });

        return isset($session['session_key']) ? (int) $session['session_key'] : null;
    }
}
