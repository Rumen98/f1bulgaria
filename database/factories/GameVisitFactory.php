<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameVisit>
 */
class GameVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_key' => hash('sha256', fake()->uuid()),
            'user_id' => null,
            'kind' => GameVisit::KIND_VIEW,
            'device' => 'desktop',
            'track_slug' => null,
            'created_at' => now(),
        ];
    }

    public function start(string $track = 'monza'): static
    {
        return $this->state(['kind' => GameVisit::KIND_START, 'track_slug' => $track]);
    }

    public function mobile(): static
    {
        return $this->state(['device' => 'mobile']);
    }
}
