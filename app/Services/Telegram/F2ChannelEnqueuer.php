<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\ChannelQueueOutcome;
use App\Models\F2RaceSession;
use App\Services\Telegram\Formatters\F2SessionFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пълни опашката с резултати от приключили F2 сесии.
 *
 * Прозорецът назад (`channel.max_backfill_hours`) пази канала при първо
 * включване сред сезона — иначе изминалите кръгове тръгват наведнъж.
 *
 * Публикуването следва хронологията на уикенда, не реда на вмъкване: при
 * наваксване планировчикът може да хване състезанието преди квалификацията и
 * каналът да обяви резултата пръв.
 */
class F2ChannelEnqueuer
{
    public function __construct(
        private readonly ChannelQueue $queue,
        private readonly F2SessionFormatter $formatter,
    ) {}

    /**
     * @return array{queued:int, updated:int, errors:array<int, string>}
     */
    public function enqueuePending(): array
    {
        $stats = ['queued' => 0, 'updated' => 0, 'errors' => []];

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
                $outcome = $this->queue->enqueue(
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

                match ($outcome) {
                    ChannelQueueOutcome::Created => $stats['queued']++,
                    ChannelQueueOutcome::Updated => $stats['updated']++,
                    ChannelQueueOutcome::Unchanged => null,
                };
            } catch (Throwable $e) {
                $stats['errors'][] = "F2 сесия #{$session->id}: {$e->getMessage()}";
                Log::warning("Канал: неуспешно поставяне на F2 сесия [{$session->id}]: {$e->getMessage()}");
            }
        }

        return $stats;
    }

    /**
     * Състезание тръгва още с временната класация, отбелязана като такава.
     *
     * Чакането на `Final` струва часове — в Унгария FIA издаде временната 15
     * минути след финала, а окончателната два часа и половина по-късно. Дотогава
     * всички вече знаят резултата и каналът мълчи.
     *
     * Затова публикуваме рано и РЕДАКТИРАМЕ същото съобщение, щом класацията
     * стане окончателна (виж ChannelQueue::refresh). Никой не получава второ
     * известие, а постът никога не остава грешен.
     *
     * `version` е null само докато резултатите още не са свалени.
     */
    private function isReady(F2RaceSession $session): bool
    {
        if (! $session->session_type->isRace()) {
            return true;
        }

        return filled($session->version);
    }
}
