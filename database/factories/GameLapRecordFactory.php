<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameLapRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameLapRecord>
 */
class GameLapRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $s1 = fake()->numberBetween(22000, 40000);
        $s2 = fake()->numberBetween(22000, 40000);
        $s3 = fake()->numberBetween(22000, 40000);

        return [
            'user_id' => User::factory(),
            'track_slug' => 'monza',
            'lap_ms' => $s1 + $s2 + $s3,
            'sector1_ms' => $s1,
            'sector2_ms' => $s2,
            'sector3_ms' => $s3,
        ];
    }
}
