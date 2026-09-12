<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GameSessionTelemetry
{
    /** Клиентът спира на 100 обиколки; сървърът не бива да брои повече, каквото и да пристигне. */
    private const MAX_LAPS = 100;

    /** @param array<string, mixed> $data */
    public function start(User $user, array $data): GameSession
    {
        $attributes = ['track_slug' => $data['track'], 'device' => $data['device'], 'mode' => $data['mode']];

        if (! isset($data['client_id'])) {
            return $user->gameSessions()->create($attributes);
        }

        return $user->gameSessions()->firstOrCreate(['client_id' => $data['client_id']], [
            ...$attributes,
            'status' => 'active',
            'context' => $data['context'] ?? [],
            'last_seen_at' => now(),
        ]);
    }

    /** @param list<array{sequence: int, type: string, active_ms: int, data?: array<string, mixed>}> $events */
    public function record(GameSession $session, array $events): void
    {
        DB::transaction(function () use ($session, $events): void {
            $session = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_if($session->client_id === null, 409, 'Тази сесия е от предишна версия на играта.');
            $receivedAt = now();

            foreach (collect($events)->sortBy('sequence') as $input) {
                $event = $session->events()->firstOrCreate(['sequence' => $input['sequence']], [
                    'type' => $input['type'],
                    'active_ms' => $input['active_ms'],
                    'data' => $this->normalize($input['data'] ?? []),
                    'received_at' => $receivedAt,
                ]);

                if (! $event->wasRecentlyCreated) {
                    continue;
                }

                $data = $event->data;
                $session->active_ms = max($session->active_ms, $event->active_ms);
                $session->max_progress = max($session->max_progress, $data['progress'] ?? 0);
                $session->max_speed = max($session->max_speed, $data['speed'] ?? 0, $data['max_speed'] ?? 0);
                $session->last_seen_at = $receivedAt;

                if ($event->type === 'lap_completed' && $session->lap_count < self::MAX_LAPS) {
                    $session->lap_count++;
                    if (isset($data['lap_valid'])) {
                        $data['lap_valid'] ? $session->valid_lap_count++ : $session->invalid_lap_count++;
                    }
                    $session->max_progress = 1;
                }

                if ($event->sequence > $session->last_sequence) {
                    $session->last_sector = $data['sector'] ?? $session->last_sector;
                    $session->last_sequence = $event->sequence;
                }
            }

            // Delivery can arrive out of order (including keepalive on page exit).
            // Rebuild lifecycle in client sequence order so a late finish is not lost.
            $session->status = 'active';
            $session->ended_at = null;
            foreach ($session->events()->orderBy('sequence')->get(['sequence', 'type', 'received_at']) as $event) {
                $this->updateStatus($session, $event->type);
                if (in_array($session->status, GameSession::TERMINAL_STATUSES, true)) {
                    $session->ended_at = $event->received_at;
                    break;
                }
            }
            $session->save();
        }, attempts: 3);
    }

    /**
     * Валидацията пуска числата и като низове („120", „0.5"); в JSON-а лягат
     * като числа, за да са сравними и да не носят дълги десетични опашки.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['progress', 'speed', 'max_speed', 'frame_ms', 'render_scale'] as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                $data[$key] = round((float) $data[$key], 3);
            }
        }
        foreach (['sector', 'lap_ms', 'lap_number', 'race_position', 'warnings'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (array_key_exists('lap_valid', $data)) {
            $data['lap_valid'] = filter_var($data['lap_valid'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    private function updateStatus(GameSession $session, string $type): void
    {
        if (in_array($session->status, GameSession::TERMINAL_STATUSES, true)) {
            return;
        }

        $session->status = match ($type) {
            'started', 'moving', 'resumed' => 'active',
            'paused', 'page_hidden' => 'paused',
            'lap_completed' => $session->mode === GameSession::MODE_SOLO ? 'completed' : $session->status,
            'race_completed' => 'completed',
            'quit' => 'quit',
            'restarted' => 'restarted',
            'page_left' => 'interrupted',
            'error' => 'error',
            default => $session->status,
        };

        if (in_array($session->status, GameSession::TERMINAL_STATUSES, true)) {
            $session->ended_at = now();
        }
    }
}
