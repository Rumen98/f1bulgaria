<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameFeedback;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameFeedback>
 */
class GameFeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_id' => null,
            'submission_key' => fn (array $attributes): string => isset($attributes['session_id']) ? "session:{$attributes['session_id']}" : 'legacy',
            'rating' => 4,
            'controls_rating' => null,
            'performance_rating' => null,
            'difficulty' => null,
            'reason' => null,
            'would_play_again' => null,
            'comment' => null,
        ];
    }

    public function forSession(GameSession $session): static
    {
        return $this->state([
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'submission_key' => "session:{$session->id}",
        ]);
    }
}
