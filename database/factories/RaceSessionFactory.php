<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionType;
use App\Models\Race;
use App\Models\RaceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceSession>
 */
class RaceSessionFactory extends Factory
{
    protected $model = RaceSession::class;

    public function definition(): array
    {
        return [
            'race_id' => Race::factory(),
            'type' => fake()->randomElement(SessionType::cases()),
            'scheduled_at_utc' => fake()->dateTimeBetween('-1 week', '+1 week'),
        ];
    }
}
