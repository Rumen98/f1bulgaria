<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Спринт резултатите носят отделни шампионатни точки, затова един пилот може
     * да има два реда за състезание (race + sprint). Уникалността става тройна.
     */
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->enum('session_type', ['race', 'sprint'])
                ->default('race')
                ->after('driver_id');
        });

        Schema::table('results', function (Blueprint $table) {
            $table->dropUnique('results_race_id_driver_id_unique');
            $table->unique(['race_id', 'driver_id', 'session_type']);
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropUnique('results_race_id_driver_id_session_type_unique');
            $table->unique(['race_id', 'driver_id']);
            $table->dropColumn('session_type');
        });
    }
};
