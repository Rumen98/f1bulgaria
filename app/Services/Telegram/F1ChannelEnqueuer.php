<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostKind;
use App\Enums\ChannelQueueOutcome;
use App\Enums\SessionType;
use App\Models\Race;
use App\Models\RaceSession;
use App\Services\Races\RaceClassificationProvider;
use App\Services\Telegram\Formatters\F1SessionFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пълни опашката с резултати от сесиите на Формула 1.
 *
 * Не решава откъде идват данните — това е работа на
 * RaceClassificationProvider. Тук се решава само КОГА може да тръгне пост.
 *
 * Състезанието излиза с временната класация от бързия източник и се
 * РЕДАКТИРА на място, когато официалната пристигне; отделно изчакване по
 * часовник няма нужда, защото бързият източник сам публикува чак след края
 * на сесията.
 */
class F1ChannelEnqueuer
{
    public function __construct(
        private readonly ChannelQueue $queue,
        private readonly F1SessionFormatter $formatter,
        private readonly RaceClassificationProvider $classifications,
    ) {}

    /**
     * @return array{queued:int, updated:int, errors:array<int, string>}
     */
    public function enqueuePending(): array
    {
        $stats = ['queued' => 0, 'updated' => 0, 'errors' => []];

        $cutoff = now()->subHours((int) config('channel.max_backfill_hours', 24));

        $races = Race::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('race_datetime_utc', '>=', $cutoff)
                    ->orWhere('qualifying_datetime_utc', '>=', $cutoff)
                    ->orWhere('sprint_datetime_utc', '>=', $cutoff);
            })
            ->with('poleDriver')
            ->orderBy('round')
            ->get();

        foreach ($races as $race) {
            foreach ($this->readyKinds($race, $cutoff) as $value => $availableAt) {
                $kind = ChannelPostKind::from($value);

                try {
                    $outcome = $this->queue->enqueue(
                        $race,
                        $kind,
                        $this->formatter->format($race, $kind),
                        $availableAt,
                    );

                    match ($outcome) {
                        ChannelQueueOutcome::Created => $stats['queued']++,
                        ChannelQueueOutcome::Updated => $stats['updated']++,
                        ChannelQueueOutcome::Unchanged => null,
                    };
                } catch (Throwable $e) {
                    $stats['errors'][] = "F1 {$value} (кръг {$race->round}): {$e->getMessage()}";
                    Log::warning("Канал: неуспешно поставяне на F1 [{$race->id}/{$value}]: {$e->getMessage()}");
                }
            }
        }

        return $stats;
    }

    /**
     * Сесиите с налична класация и кога най-рано могат да тръгнат.
     *
     * Ключът на подредбата е `available_at` — така тренировката излиза преди
     * квалификацията, а тя преди състезанието, независимо в какъв ред
     * синхроните са ги записали.
     *
     * @return array<string, Carbon>
     */
    private function readyKinds(Race $race, Carbon $cutoff): array
    {
        $kinds = [];

        // Разписанието идва от OpenF1 (виж OpenF1SessionSync::syncSchedule).
        $schedule = RaceSession::query()
            ->where('race_id', $race->id)
            ->get()
            ->mapWithKeys(fn (RaceSession $s): array => [$s->type->value => $s->scheduled_at_utc]);

        foreach (ChannelPostKind::cases() as $kind) {
            $type = $kind->sessionType();

            if ($type === null || $this->classifications->for($race, $type) === null) {
                continue;
            }

            $at = $schedule[$type->value] ?? $this->fallbackTime($race, $type);

            // Тренировките са в петък, състезанието в неделя. Без проверка по
            // собственото им време петъчната тренировка би тръгнала в неделя,
            // защото кръгът още е в прозореца.
            if ($at === null || $at->lt($cutoff)) {
                continue;
            }

            $kinds[$kind->value] = $at;
        }

        return $kinds;
    }

    /**
     * Резервно време, ако разписанието още не е синхронизирано.
     */
    private function fallbackTime(Race $race, SessionType $type): ?Carbon
    {
        return match ($type) {
            SessionType::Qualifying => $race->qualifying_datetime_utc,
            SessionType::Sprint => $race->sprint_datetime_utc,
            SessionType::Race => $race->race_datetime_utc,
            default => null,
        };
    }
}
