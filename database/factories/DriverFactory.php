<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->unique()->lastName();

        return [
            'season_id' => Season::factory(),
            'constructor_id' => Constructor::factory(),
            'jolpica_id' => Str::slug("{$first} {$last}"),
            'driver_code' => Str::upper(Str::substr($last, 0, 3)),
            'first_name' => $first,
            'last_name' => $last,
            'slug' => Str::slug("{$first} {$last}"),
            'permanent_number' => fake()->unique()->numberBetween(1, 99),
            'country_code' => fake()->randomElement(['GBR', 'NLD', 'DEU', 'ESP', 'MCO']),
        ];
    }
}
