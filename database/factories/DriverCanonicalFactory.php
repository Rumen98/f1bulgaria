<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DriverCanonical;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DriverCanonical>
 */
class DriverCanonicalFactory extends Factory
{
    protected $model = DriverCanonical::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'code' => Str::upper(substr($last, 0, 3)),
            'first_name' => $first,
            'last_name' => $last,
            'slug' => Str::slug("{$first} {$last}").'-'.fake()->unique()->numberBetween(1, 99999),
            'country_code' => 'GBR',
            'is_active' => false,
            'total_wins' => 0,
            'total_podiums' => 0,
            'total_poles' => 0,
            'total_races' => 0,
        ];
    }
}
