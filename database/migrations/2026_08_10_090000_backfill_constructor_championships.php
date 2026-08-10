<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `championships_count` беше добавена като ръчно поддържана колона и никога не
 * беше попълвана — на страницата на всеки отбор „Титли" стоеше 0, включително
 * за Ферари. Пълним я от config/team-championships.php, за да се оправи и на
 * продукция при деплой (там се пускат само миграции, не и сийдъри).
 *
 * Пипаме само записи с 0 — ръчна корекция през Filament не се губи.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, int> $titles */
        $titles = config('team-championships', []);

        foreach ($titles as $slug => $count) {
            DB::table('constructors_canonical')
                ->where('slug', $slug)
                ->where('championships_count', 0)
                ->update(['championships_count' => $count]);
        }
    }

    public function down(): void
    {
        DB::table('constructors_canonical')
            ->whereIn('slug', array_keys((array) config('team-championships', [])))
            ->update(['championships_count' => 0]);
    }
};
