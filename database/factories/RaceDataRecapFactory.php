<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Race;
use App\Models\RaceDataRecap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceDataRecap>
 */
class RaceDataRecapFactory extends Factory
{
    protected $model = RaceDataRecap::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'race_id' => Race::factory(),
            'openf1_session_key' => fake()->numberBetween(9000, 12000),
            'openf1_quali_session_key' => fake()->numberBetween(9000, 12000),
            'facts' => [
                'laps' => 53,
                'drivers' => 20,
                'winner' => ['name' => 'Ландо Норис', 'team' => 'McLaren', 'colour' => '#FF8000', 'slug' => 'norris', 'gap_to_second' => 4.2, 'laps' => 53],
                'fastest_lap' => ['name' => 'Ландо Норис', 'colour' => '#FF8000', 'seconds' => 81.5, 'display' => '1:21.500', 'lap' => 44],
                'top_speed' => ['name' => 'Ландо Норис', 'colour' => '#FF8000', 'kmh' => 338, 'lap' => 12],
                'bullets' => ['Тестова точка едно.', 'Тестова точка две.'],
                'narrative_by_llm' => false,
            ],
            'charts' => ['total_laps' => 53],
            'headline' => 'Какво казват данните от '.fake()->city(),
            'body_bg' => "Първи абзац.\n\nВтори абзац.",
            'attempts' => 0,
            'generated_at' => now(),
        ];
    }

    /** Опит, който е паднал — има ред, но няма какво да покаже. */
    public function failed(): self
    {
        return $this->state(fn () => [
            'facts' => null,
            'charts' => null,
            'headline' => null,
            'body_bg' => null,
            'generated_at' => null,
            'attempts' => 3,
            'last_error' => 'Данните не минаха санитарните граници.',
        ]);
    }
}
