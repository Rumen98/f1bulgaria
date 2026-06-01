<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Badge;
use App\Services\Badges\BadgeService;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (BadgeService::DEFINITIONS as $slug => $definition) {
            Badge::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                ],
            );
        }
    }
}
