<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\GameFeedback;
use App\Models\User;

class GameFeedbackService
{
    /** @param array<string, mixed> $answers */
    public function store(User $user, array $answers): GameFeedback
    {
        $sessionId = isset($answers['session_id']) ? (int) $answers['session_id'] : null;

        return GameFeedback::query()->updateOrCreate([
            'user_id' => $user->id,
            'submission_key' => $sessionId === null ? 'legacy' : "session:{$sessionId}",
        ], [
            ...$answers,
            'session_id' => $sessionId,
        ]);
    }

    /** @return array{eligible: bool, last_session_id: ?int, submitted: bool} */
    public function prompt(?User $user): array
    {
        if ($user === null || $user->isBanned()) {
            return ['eligible' => false, 'last_session_id' => null, 'submitted' => false];
        }

        $sessionId = $user->gameSessions()->latest('id')->value('id');
        $eligible = $sessionId !== null || $user->gameLapRecords()->exists();

        // „submitted" = дал ли е мнение изобщо: формата се отваря сама само
        // за първото. Мнение за конкретно каране остава възможно през бутона.
        return [
            'eligible' => $eligible,
            'last_session_id' => $sessionId === null ? null : (int) $sessionId,
            'submitted' => $eligible && GameFeedback::query()->where('user_id', $user->id)->exists(),
        ];
    }
}
