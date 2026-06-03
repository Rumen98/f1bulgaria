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
        // Детерминистичен, уникален в рамките на процеса. Започва от 2100 нагоре —
        // нарочно извън диапазона, който тестовете задават явно (2018–2026), за да
        // не се сблъскват авто-генерираните години с конкретно зададените.
        static $year = 2100;

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
