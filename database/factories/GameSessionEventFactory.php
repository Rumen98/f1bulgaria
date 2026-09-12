<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameSession;
use App\Models\GameSessionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameSessionEvent>
 */
class GameSessionEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_session_id' => GameSession::factory()->tracked(),
            'sequence' => 1,
            'type' => 'started',
            'active_ms' => 0,
            'data' => [],
            'received_at' => now(),
        ];
    }
}
