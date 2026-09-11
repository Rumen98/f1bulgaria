<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключът на пистата в OpenF1 — от него се сглобява адресът към геометрията ѝ
 * в MultiViewer (очертание + номера на завоите за картата по скорост).
 *
 * Пази се в реда, за да не се дърпа календарът на сезона наново при всяко
 * преизчисляване на рекапа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_data_recaps', function (Blueprint $table) {
            $table->unsignedBigInteger('openf1_circuit_key')->nullable()->after('openf1_quali_session_key');
        });
    }

    public function down(): void
    {
        Schema::table('race_data_recaps', function (Blueprint $table) {
            $table->dropColumn('openf1_circuit_key');
        });
    }
};
