<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        // Детерминистичен, уникален в рамките на процеса — избягва flaky
        // unique колизии в пълния suite (за разлика от fake()->unique()).
        static $year = 1990;

        return [
            'year' => $year++,
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }
}
