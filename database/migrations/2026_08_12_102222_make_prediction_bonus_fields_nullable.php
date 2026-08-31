<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бонус полетата стават по избор: задължителен остава само подиумът.
 *
 * Драйвър колоните вече бяха nullable, но `dnf_count` и `safety_car` имаха
 * NOT NULL с default 0/false — при тях „не отговорих" беше неразличимо от
 * „прогнозирам 0 отпаднали" и „прогнозирам без safety car", което носеше
 * точки за неподаден отговор.
 *
 * Съществуващите редове НЕ се пипат: там 0/false са реални отговори.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->unsignedTinyInteger('dnf_count')->nullable()->default(null)->change();
            $table->boolean('safety_car')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Връщането изисква стойност за вече празните редове, иначе NOT NULL
        // гърми. 0/false е същото, което схемата даваше по подразбиране.
        DB::table('predictions')->whereNull('dnf_count')->update(['dnf_count' => 0]);
        DB::table('predictions')->whereNull('safety_car')->update(['safety_car' => false]);

        Schema::table('predictions', function (Blueprint $table) {
            $table->unsignedTinyInteger('dnf_count')->default(0)->nullable(false)->change();
            $table->boolean('safety_car')->default(false)->nullable(false)->change();
        });
    }
};
