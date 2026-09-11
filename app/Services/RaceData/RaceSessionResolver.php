<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;
use App\Models\RaceDataRecap;
use App\Services\LiveTiming\OpenF1Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Намира ключовете на състезанието и квалификацията в OpenF1 по наш Race.
 *
 * Мачването е ПО ДАТА, не по име на Гран При. Причината е конкретна: през 2026
 * Барселона и Мадрид си разменят имената („Barcelona Grand Prix“ и „Spanish
 * Grand Prix“), така че карта по име би закачила данните на единия кръг към
 * другия — без 404 и без изключение, само правдоподобни грешни числа. Датата
 * няма как да сгреши по този начин.
 *
 * Веднъж намерените ключове се записват в `race_data_recaps`, за да не се
 * дърпа календарът на сезона при всяко пускане.
 */
class RaceSessionResolver
{
    /** Прозорец около нашата дата, в който търсим сесията на OpenF1. */
    private const MATCH_WINDOW_DAYS = 2;

    public function __construct(private readonly OpenF1Client $client) {}

    public function resolve(Race $race, ?RaceDataRecap $recap = null): ?RaceSessionKeys
    {
        $ourDate = $race->race_datetime_utc;

        if ($recap?->openf1_session_key !== null && $recap->openf1_circuit_key !== null) {
            return new RaceSessionKeys(
                race: $recap->openf1_session_key,
                qualifying: $recap->openf1_quali_session_key,
                circuit: $recap->openf1_circuit_key,
                year: $ourDate?->year,
            );
        }

        if ($ourDate === null) {
            return null;
        }

        $sessions = $this->client->getSeasonSessions($ourDate->year);

        if ($sessions->isEmpty()) {
            return null;
        }

        $weekend = $this->weekendAround($sessions, $ourDate);

        if ($weekend->isEmpty()) {
            return null;
        }

        $raceSession = $this->pick($weekend, 'Race');

        if ($raceSession === null) {
            return null;
        }

        $qualifying = $this->pick($weekend, 'Qualifying');

        return new RaceSessionKeys(
            race: (int) $raceSession['session_key'],
            // Именно „Qualifying“, не „Sprint Qualifying“: решетката на
            // главното състезание идва от нея дори в спринт уикенд.
            qualifying: $qualifying !== null ? (int) $qualifying['session_key'] : null,
            meeting: isset($raceSession['meeting_key']) ? (int) $raceSession['meeting_key'] : null,
            circuit: isset($raceSession['circuit_key']) ? (int) $raceSession['circuit_key'] : null,
            year: $ourDate->year,
        );
    }

    /**
     * Сесиите от уикенда около нашата дата.
     *
     * @param  Collection<int, array<string, mixed>>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function weekendAround(Collection $sessions, Carbon $ourDate): Collection
    {
        $from = $ourDate->copy()->subDays(self::MATCH_WINDOW_DAYS);
        $to = $ourDate->copy()->addDays(self::MATCH_WINDOW_DAYS);

        return $sessions->filter(function (array $session) use ($from, $to): bool {
            $start = $this->parseDate($session['date_start'] ?? null);

            return $start !== null && $start->between($from, $to);
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weekend
     * @return array<string, mixed>|null
     */
    private function pick(Collection $weekend, string $name): ?array
    {
        return $weekend->first(
            fn (array $s) => strcasecmp((string) ($s['session_name'] ?? ''), $name) === 0,
        );
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
