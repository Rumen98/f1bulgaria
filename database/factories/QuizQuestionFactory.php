<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuizQuestion> */
class QuizQuestionFactory extends Factory
{
    protected $model = QuizQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->slug(3),
            'question' => $this->faker->sentence().'?',
            'option_1' => $this->faker->word(),
            'option_2' => $this->faker->word(),
            'option_3' => $this->faker->word(),
            'option_4' => $this->faker->word(),
            'correct_option' => $this->faker->numberBetween(1, 4),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Фиксира верния отговор — за детерминистично оценяване в тестове. */
    public function correct(int $option): static
    {
        return $this->state(fn () => ['correct_option' => $option]);
    }
}
