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
        foreach ($this->titles() as $slug => $count) {
            DB::table('constructors_canonical')
                ->where('slug', $slug)
                ->where('championships_count', 0)
                ->update(['championships_count' => $count]);
        }
    }

    public function down(): void
    {
        DB::table('constructors_canonical')
            ->whereIn('slug', array_keys($this->titles()))
            ->update(['championships_count' => 0]);
    }

    /**
     * Файлът се чете директно, а НЕ през config(): deploy.sh пуска `migrate`
     * преди `optimize:clear`, така че в този момент е активен конфиг кешът от
     * предишния деплой и новият ключ още не съществува в него.
     *
     * @return array<string, int>
     */
    private function titles(): array
    {
        $path = config_path('team-championships.php');

        return file_exists($path) ? (array) require $path : [];
    }
};
