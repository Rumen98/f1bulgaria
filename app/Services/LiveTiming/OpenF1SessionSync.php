<?php

declare(strict_types=1);

namespace App\Services\LiveTiming;

use App\Enums\SessionType;
use App\Models\Driver;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Season;
use App\Models\SessionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Класации от сесиите, които Jolpica не покрива: трите тренировки и спринт
 * квалификацията. Източник — OpenF1.
 *
 * ЛИЦЕНЗ: OpenF1 е CC BY-NC-SA 4.0 и изисква посочване на източника. Затова
 * постовете с тези данни носят атрибуция (виж ChannelPostKind). Употребата е
 * некомерсиална; ако сайтът някога вземе реклами, това трябва да се преразгледа
 * с тях (https://openf1.org/contact).
 *
 * ДОСТЪП: OpenF1 заключва ЦЕЛИЯ си API за неавтентикирани от 30 минути преди
 * до 30 минути след всяка сесия. Без OPENF1_USERNAME/OPENF1_PASSWORD синхронът
 * мълчи точно през уикенда, когато е нужен.
 *
 * Клиентът е защитен и връща празна колекция при всяка грешка, включително
 * 401 — затова празен резултат тук значи „още не" и се пробва пак, а не
 * „сесията няма класация". Причината се вижда в лога от клиента.
 */
class OpenF1SessionSync
{
    /**
     * Кои сесии взимаме. Квалификацията и състезанието идват от Jolpica,
     * който няма лицензно ограничение — не ги дублираме.
     *
     * Съпоставяме по МАЛКИ БУКВИ и по няколко варианта: OpenF1 подава
     * `session_name` както го дава Формула 1, а той се е менял в годините
     * („Sprint Shootout" стана „Sprint Qualifying"). Филтър по точен низ тук
     * е най-честият начин тази интеграция да замълчи без грешка.
     *
     * @var array<string, SessionType>
     */
    private const SESSION_NAMES = [
        'practice 1' => SessionType::FP1,
        'practice 2' => SessionType::FP2,
        'practice 3' => SessionType::FP3,
        'sprint qualifying' => SessionType::SprintQuali,
        'sprint shootout' => SessionType::SprintQuali,
    ];

    /**
     * Пълното съответствие — за разписанието в `race_sessions`.
     *
     * Таблицата стои празна от създаването си: никой синхрон не я е пълнил, а
     * началната страница я чете, за да покаже часовете на сесиите. OpenF1 ги
     * дава с точност до секундата, така че я пълним оттук.
     *
     * @var array<string, SessionType>
     */
    private const ALL_SESSION_NAMES = self::SESSION_NAMES + [
        'qualifying' => SessionType::Qualifying,
        'sprint' => SessionType::Sprint,
        'race' => SessionType::Race,
    ];

    public function __construct(
        private readonly OpenF1Client $client,
    ) {}

    /**
     * @return array{sessions:int, results:int, skipped:int, errors:array<int, string>}
     */
    public function syncSeason(int $year): array
    {
        $stats = ['sessions' => 0, 'results' => 0, 'skipped' => 0, 'errors' => []];

        $season = Season::query()->where('year', $year)->first();

        if ($season === null) {
            $stats['errors'][] = "Няма сезон {$year} в базата — пусни първо f1:sync-season.";

            return $stats;
        }

        $all = $this->client->getSeasonSessions($year);

        if ($all->isEmpty()) {
            $stats['errors'][] = 'OpenF1 не върна сесии (възможно е да тече сесия и достъпът да е заключен).';

            return $stats;
        }

        foreach ($all->groupBy('meeting_key') as $meetingSessions) {
            $race = $this->matchRace($season, $meetingSessions);

            if ($race === null) {
                $stats['skipped']++;

                continue;
            }

            $this->syncSchedule($race, $meetingSessions);

            foreach ($meetingSessions as $session) {
                $type = $this->sessionType($session);

                if ($type === null || ! $this->hasFinished($session)) {
                    continue;
                }

                try {
                    $count = $this->syncSession($season, $race, (int) $session['session_key'], $type);

                    if ($count > 0) {
                        $stats['sessions']++;
                        $stats['results'] += $count;
                    }
                } catch (Throwable $e) {
                    $stats['errors'][] = "кръг {$race->round} {$type->value}: {$e->getMessage()}";
                    Log::warning("OpenF1: провалена сесия [{$session['session_key']}]: {$e->getMessage()}");
                }
            }
        }

        return $stats;
    }

    /**
     * Съпоставя събитие от OpenF1 с наш кръг по датата на състезанието.
     *
     * По дата, а не по име на пистата: имената се пишат различно в двата
     * източника („Autodromo Nazionale Monza" срещу „monza"), а два Гран При
     * в един и същи ден не съществуват.
     *
     * @param  Collection<int, array<string, mixed>>  $sessions
     */
    private function matchRace(Season $season, Collection $sessions): ?Race
    {
        $raceSession = $sessions->first(
            fn ($s) => strtolower((string) ($s['session_name'] ?? '')) === 'race',
        );

        $date = $this->parseDate($raceSession['date_start'] ?? null)
            ?? $this->parseDate($sessions->first()['date_start'] ?? null);

        if ($date === null) {
            return null;
        }

        return Race::query()
            ->where('season_id', $season->id)
            ->whereNotNull('race_datetime_utc')
            ->whereBetween('race_datetime_utc', [
                $date->copy()->subDays(2),
                $date->copy()->addDays(2),
            ])
            ->first();
    }

    /**
     * Разписанието на уикенда в `race_sessions`.
     *
     * @param  Collection<int, array<string, mixed>>  $sessions
     */
    private function syncSchedule(Race $race, Collection $sessions): void
    {
        foreach ($sessions as $session) {
            $name = strtolower(trim((string) ($session['session_name'] ?? '')));
            $type = self::ALL_SESSION_NAMES[$name] ?? null;
            $start = $this->parseDate($session['date_start'] ?? null);

            if ($type === null || $start === null) {
                continue;
            }

            RaceSession::query()->updateOrCreate(
                ['race_id' => $race->id, 'type' => $type->value],
                ['scheduled_at_utc' => $start],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionType(array $session): ?SessionType
    {
        $name = strtolower(trim((string) ($session['session_name'] ?? '')));

        return self::SESSION_NAMES[$name] ?? null;
    }

    /**
     * Сесията е приключила и класацията ѝ вече не се мени.
     *
     * `date_end` е ПЛАНИРАНИЯТ край, не картираният флаг — при червен флаг
     * сесията го надхвърля. Затова добавяме резерв, който покрива и
     * 30-минутния прозорец, в който OpenF1 заключва достъпа.
     *
     * @param  array<string, mixed>  $session
     */
    private function hasFinished(array $session): bool
    {
        if (($session['is_cancelled'] ?? false) === true) {
            return false;
        }

        $end = $this->parseDate($session['date_end'] ?? null);

        return $end !== null && $end->addMinutes(35)->isPast();
    }

    private function syncSession(Season $season, Race $race, int $sessionKey, SessionType $type): int
    {
        $rows = $this->client->getSessionResult($sessionKey);

        if ($rows->isEmpty()) {
            return 0;
        }

        // Класацията носи само номера на болида — имената идват отделно.
        $drivers = $this->client->getSessionDrivers($sessionKey)->keyBy('driver_number');

        $count = 0;

        foreach ($rows as $row) {
            $driver = $this->resolveDriver($season, (int) $row['driver_number'], $drivers);

            if ($driver === null) {
                continue;
            }

            SessionResult::query()->updateOrCreate(
                [
                    'race_id' => $race->id,
                    'session_type' => $type->value,
                    'driver_id' => $driver->id,
                ],
                [
                    'position' => isset($row['position']) && $row['position'] !== null
                        ? (int) $row['position']
                        : null,
                    'best_time' => $this->lapTime($row['duration'] ?? null),
                    'gap' => $this->gap($row['gap_to_leader'] ?? null),
                    'source' => 'openf1',
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $drivers
     */
    private function resolveDriver(Season $season, int $number, Collection $drivers): ?Driver
    {
        $byNumber = Driver::query()
            ->where('season_id', $season->id)
            ->where('permanent_number', $number)
            ->first();

        if ($byNumber !== null) {
            return $byNumber;
        }

        // Резервен вариант по трибуквения код — номерът се мени при смяна на
        // отбор в рамките на сезона по-често, отколкото кодът.
        $acronym = $drivers->get($number)['name_acronym'] ?? null;

        if (! is_string($acronym) || $acronym === '') {
            return null;
        }

        return Driver::query()
            ->where('season_id', $season->id)
            ->where('driver_code', $acronym)
            ->first();
    }

    /**
     * Секунди във вид „1:23.456".
     *
     * Масив означава квалификационна сесия ([Q1, Q2, Q3]) — взимаме най-добрата
     * налична отсечка. За тренировка стойността е обикновено число.
     */
    private function lapTime(mixed $duration): ?string
    {
        if (is_array($duration)) {
            $best = collect($duration)->filter(fn ($v) => is_numeric($v))->max();

            return $best !== null ? $this->lapTime($best) : null;
        }

        if (! is_numeric($duration) || (float) $duration <= 0) {
            return null;
        }

        $seconds = (float) $duration;
        $minutes = (int) floor($seconds / 60);

        return $minutes > 0
            ? sprintf('%d:%06.3f', $minutes, $seconds - $minutes * 60)
            : sprintf('%.3f', $seconds);
    }

    /**
     * Изоставането може да е число (секунди), масив (по отсечки) или низ
     * („+1 LAP") — виж коментара при getSessionResult.
     */
    private function gap(mixed $gap): ?string
    {
        if (is_array($gap)) {
            $gap = collect($gap)->first(fn ($v) => is_numeric($v));
        }

        if (is_string($gap) && $gap !== '') {
            return $gap;
        }

        if (! is_numeric($gap) || (float) $gap <= 0) {
            return null;
        }

        return '+'.sprintf('%.3f', (float) $gap);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
