<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsStatus;
use App\Models\TeamNewsItem;
use App\Models\TeamNewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamNewsItem>
 */
class TeamNewsItemFactory extends Factory
{
    protected $model = TeamNewsItem::class;

    public function definition(): array
    {
        return [
            'source_id' => TeamNewsSource::factory(),
            'constructor_id' => null,
            'external_url' => fake()->unique()->url(),
            'external_guid' => fake()->unique()->uuid(),
            'title_original' => fake()->sentence(),
            'title_bg' => null,
            'summary_bg' => null,
            'content_snippet' => fake()->paragraph(),
            'published_at' => fake()->dateTimeBetween('-1 month'),
            'classification' => null,
            'importance_score' => null,
            'status' => NewsStatus::Pending->value,
        ];
    }

    /**
     * Публично видима статия (одобрена, с бг заглавие).
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => NewsStatus::Approved->value,
            'title_bg' => fake()->sentence(),
            'summary_bg' => fake()->paragraph(),
        ]);
    }
}
