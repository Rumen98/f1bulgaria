<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Prediction;
use App\Models\Race;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prediction>
 */
class PredictionFactory extends Factory
{
    protected $model = Prediction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'race_id' => Race::factory(),
            'p1_driver_id' => Driver::factory(),
            'p2_driver_id' => Driver::factory(),
            'p3_driver_id' => Driver::factory(),
            'pole_driver_id' => Driver::factory(),
            'fastest_lap_driver_id' => Driver::factory(),
            'dnf_count' => fake()->numberBetween(0, 8),
            'safety_car' => fake()->boolean(),
            'locked_at' => null,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn () => ['locked_at' => now()->subHour()]);
    }
}
