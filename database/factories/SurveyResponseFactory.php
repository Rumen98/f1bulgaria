<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WouldRecommend;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyResponse>
 */
class SurveyResponseFactory extends Factory
{
    protected $model = SurveyResponse::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'would_recommend' => fake()->randomElement(WouldRecommend::cases()),
            'comment' => fake()->optional()->sentence(),
            'source' => 'prompt',
            'submitted_at' => now(),
            'dismissed_at' => null,
        ];
    }

    /**
     * Ред само от скриване на картата — без отговори.
     */
    public function dismissed(): static
    {
        return $this->state(fn () => [
            'rating' => null,
            'would_recommend' => null,
            'comment' => null,
            'submitted_at' => null,
            'dismissed_at' => now(),
        ]);
    }
}
