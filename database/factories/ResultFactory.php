<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Race;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Result>
 */
class ResultFactory extends Factory
{
    protected $model = Result::class;

    public function definition(): array
    {
        $position = fake()->numberBetween(1, 20);

        return [
            'race_id' => Race::factory(),
            'driver_id' => Driver::factory(),
            'jolpica_id' => fake()->uuid(),
            'position' => $position,
            'points' => max(0, 26 - $position),
            'dnf' => false,
            'fastest_lap' => false,
            'grid_position' => fake()->numberBetween(1, 20),
        ];
    }

    public function dnf(): static
    {
        return $this->state(fn () => [
            'position' => null,
            'points' => 0,
            'dnf' => true,
        ]);
    }

    public function position(int $position): static
    {
        return $this->state(fn () => [
            'position' => $position,
            'points' => max(0, 26 - $position),
            'dnf' => false,
        ]);
    }
}
