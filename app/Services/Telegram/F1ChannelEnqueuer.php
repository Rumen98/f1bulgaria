<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelPostKind;
use App\Enums\ChannelQueueOutcome;
use App\Enums\ResultSessionType;
use App\Enums\SessionType;
use App\Models\Race;
use App\Models\RaceSession;
use App\Models\Result;
use App\Models\SessionResult;
use App\Services\Telegram\Formatters\F1SessionFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пълни опашката с резултати от сесиите на Формула 1.
 *
 * Разликата с F2: Jolpica няма поле „окончателна класация". Затова
 * състезанието се отлага с `channel.race_result_delay_minutes` — стюардите
 * пренареждат класирането след финала, а Jolpica обновява данните си без да
 * казва кога вече са окончателни.
 */
class F1ChannelEnqueuer
{
    public function __construct(
        private readonly ChannelQueue $queue,
        private readonly F1SessionFormatter $formatter,
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
            foreach ($this->readyKinds($race) as $kind => $availableAt) {
                try {
                    $outcome = $this->queue->enqueue(
                        $race,
                        ChannelPostKind::from($kind),
                        $this->formatter->format($race, ChannelPostKind::from($kind)),
                        $availableAt,
                    );

                    match ($outcome) {
                        ChannelQueueOutcome::Created => $stats['queued']++,
                        ChannelQueueOutcome::Updated => $stats['updated']++,
                        ChannelQueueOutcome::Unchanged => null,
                    };
                } catch (Throwable $e) {
                    $stats['errors'][] = "F1 {$kind} (кръг {$race->round}): {$e->getMessage()}";
                    Log::warning("Канал: неуспешно поставяне на F1 [{$race->id}/{$kind}]: {$e->getMessage()}");
                }
            }
        }

        return $stats;
    }

    /**
     * Сесиите с налични резултати и кога най-рано могат да тръгнат.
     *
     * Ключът на подредбата е `available_at` — така квалификацията излиза
     * преди спринта, а спринтът преди състезанието, независимо в какъв ред
     * синхронът ги е заварил.
     *
     * @return array<string, Carbon|null>
     */
    private function readyKinds(Race $race): array
    {
        $kinds = [];
        $cutoff = now()->subHours((int) config('channel.max_backfill_hours', 24));

        // Разписанието идва от OpenF1 (виж OpenF1SessionSync::syncSchedule).
        $schedule = RaceSession::query()
            ->where('race_id', $race->id)
            ->get()
            ->mapWithKeys(fn (RaceSession $s): array => [$s->type->value => $s->scheduled_at_utc]);

        $present = SessionResult::query()
            ->where('race_id', $race->id)
            ->distinct()
            ->pluck('session_type');

        foreach ($present as $value) {
            // pluck минава през каста на модела и връща вече готов enum;
            // низът е за случая, в който колоната се чете сурово.
            $type = $value instanceof SessionType
                ? $value
                : SessionType::tryFrom((string) $value);

            if ($type === null) {
                continue;
            }

            $at = $schedule[$type->value]
                ?? ($type === SessionType::Qualifying ? $race->qualifying_datetime_utc : null);

            // Тренировките са в петък, състезанието в неделя. Без проверка по
            // собственото им време, петъчната тренировка би тръгнала в неделя,
            // защото кръгът още е в прозореца.
            if ($at === null || $at->lt($cutoff)) {
                continue;
            }

            $kinds[ChannelPostKind::fromF1SessionType($type)->value] = $at;
        }

        if ($this->hasResults($race, ResultSessionType::Sprint)) {
            $kinds[ChannelPostKind::F1Sprint->value] = $race->sprint_datetime_utc;
        }

        if ($this->hasResults($race, ResultSessionType::Race)) {
            // Отлагане само за състезанието: там наказанията след финала
            // променят подиума, а квалификацията рядко се пренарежда.
            $delay = (int) config('channel.race_result_delay_minutes', 45);

            $kinds[ChannelPostKind::F1Race->value] = $race->race_datetime_utc?->copy()->addMinutes($delay);
        }

        return $kinds;
    }

    private function hasResults(Race $race, ResultSessionType $type): bool
    {
        return Result::query()
            ->where('race_id', $race->id)
            ->where('session_type', $type->value)
            ->exists();
    }
}
