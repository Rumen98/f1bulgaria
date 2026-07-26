<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\F2RaceSession;
use App\Services\Telegram\Formatters\F2SessionFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пълни опашката с резултати от приключили F2 сесии.
 *
 * Две предпазни мерки, без които първото пускане би заляло канала:
 *
 * 1. Прозорец назад (`channel.max_backfill_hours`) — при включване на канала
 *    сред сезона не искаме деветте изминали кръга да тръгнат наведнъж.
 * 2. Състезанията чакат `version = Final`. Това е по-добър сигнал от
 *    изчакване по часовник: стюардите се произнасят когато се произнесат.
 */
class F2ChannelEnqueuer
{
    public function __construct(
        private readonly ChannelQueue $queue,
        private readonly F2SessionFormatter $formatter,
    ) {}

    /**
     * @return array{queued:int, errors:array<int, string>}
     */
    public function enqueuePending(): array
    {
        $stats = ['queued' => 0, 'errors' => []];

        $cutoff = now()->subHours((int) config('channel.max_backfill_hours', 24));

        $sessions = F2RaceSession::query()
            ->where('state', 'completed')
            ->where('ends_at_utc', '>=', $cutoff)
            ->whereHas('results')
            ->with(['race.season', 'results.driver.team'])
            ->orderBy('ends_at_utc')
            ->get()
            ->filter(fn (F2RaceSession $session): bool => $this->isReady($session));

        foreach ($sessions as $session) {
            try {
                $queued = $this->queue->enqueue(
                    $session,
                    $session->session_type->channelPostKind(),
                    $this->formatter->format($session),
                    // available_at = краят на сесията, макар и в миналото.
                    // Опашката се подрежда по него, така публикуването следва
                    // хронологията на уикенда. Без това редът е този на
                    // вмъкване и каналът може да обяви резултата от
                    // състезанието преди квалификацията — спойлер отгоре.
                    $session->ends_at_utc,
                );

                if ($queued) {
                    $stats['queued']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "F2 сесия #{$session->id}: {$e->getMessage()}";
                Log::warning("Канал: неуспешно поставяне на F2 сесия [{$session->id}]: {$e->getMessage()}");
            }
        }

        return $stats;
    }

    /**
     * Състезание се публикува само с окончателна класация — иначе каналът
     * обявява подиум, който стюардите после променят.
     */
    private function isReady(F2RaceSession $session): bool
    {
        if (! $session->session_type->isRace()) {
            return true;
        }

        return $session->version === 'Final';
    }
}
