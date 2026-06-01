<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Race;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Race>
 */
class RaceFactory extends Factory
{
    protected $model = Race::class;

    public function definition(): array
    {
        $raceAt = fake()->dateTimeBetween('-1 month', '+1 month');
        $qualifyingAt = (clone $raceAt)->modify('-1 day');

        return [
            'season_id' => Season::factory(),
            'jolpica_id' => fake()->slug(2),
            'round' => fake()->unique()->numberBetween(1, 24),
            'name' => fake()->city().' Grand Prix',
            'circuit' => fake()->streetName().' Circuit',
            'country' => fake()->country(),
            'race_datetime_utc' => $raceAt,
            'qualifying_datetime_utc' => $qualifyingAt,
            'sprint_datetime_utc' => null,
            'has_sprint' => false,
            'pole_driver_id' => null,
            'had_safety_car' => null,
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn () => [
            'race_datetime_utc' => now()->addWeeks(2),
            'qualifying_datetime_utc' => now()->addWeeks(2)->subDay(),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'race_datetime_utc' => now()->subWeek(),
            'qualifying_datetime_utc' => now()->subWeek()->subDay(),
        ]);
    }
}
