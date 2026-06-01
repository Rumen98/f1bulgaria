<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Constructor;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Constructor>
 */
class ConstructorFactory extends Factory
{
    protected $model = Constructor::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'season_id' => Season::factory(),
            'jolpica_id' => Str::slug($name),
            'name' => $name,
            'slug' => Str::slug($name),
            'color_hex' => fake()->hexColor(),
        ];
    }
}
