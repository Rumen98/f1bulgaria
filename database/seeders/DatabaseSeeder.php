<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BadgeSeeder::class);
        $this->call(TeamNewsSourceSeeder::class);
        // Зависи от каноничните пилоти (drivers:backfill-canonical) — пропуска липсващите.
        $this->call(RivalrySeeder::class);

        // Администраторски акаунт (Румен) за достъп до Filament панела.
        User::query()->updateOrCreate(
            ['email' => 'itcashbroker@gmail.com'],
            [
                'name' => 'Румен',
                'password' => 'password',
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
