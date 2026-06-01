<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Prediction;
use App\Models\PredictionScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PredictionScore>
 */
class PredictionScoreFactory extends Factory
{
    protected $model = PredictionScore::class;

    public function definition(): array
    {
        $points = fake()->numberBetween(0, 80);

        return [
            'prediction_id' => Prediction::factory(),
            'points' => $points,
            'breakdown_json' => ['p1' => $points],
        ];
    }
}
