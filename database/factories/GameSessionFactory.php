<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameSession>
 */
class GameSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'track_slug' => 'monza',
            'device' => GameSession::DEVICE_DESKTOP,
            'mode' => GameSession::MODE_SOLO,
        ];
    }

    public function mobile(): static
    {
        return $this->state(['device' => GameSession::DEVICE_MOBILE]);
    }

    public function tracked(): static
    {
        return $this->state(fn () => ['client_id' => fake()->uuid(), 'status' => 'active', 'last_seen_at' => now()]);
    }

    public function race(): static
    {
        return $this->state(['mode' => GameSession::MODE_RACE]);
    }
}
