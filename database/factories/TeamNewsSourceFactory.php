<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Constructor;
use App\Models\TeamNewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamNewsSource>
 */
class TeamNewsSourceFactory extends Factory
{
    protected $model = TeamNewsSource::class;

    public function definition(): array
    {
        return [
            'constructor_id' => null,
            'name' => fake()->unique()->company(),
            'feed_url' => fake()->unique()->url().'/feed',
            'type' => 'rss',
            'language' => 'en',
            'is_active' => true,
            'last_fetched_at' => null,
        ];
    }

    public function forConstructor(Constructor $constructor): static
    {
        return $this->state(fn () => ['constructor_id' => $constructor->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
