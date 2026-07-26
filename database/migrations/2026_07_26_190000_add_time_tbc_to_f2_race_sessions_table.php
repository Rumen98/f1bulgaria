<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отбелязва сесиите, за които официалният API още не е обявил точен час.
 *
 * Бъдещите кръгове идват с `startTime` точно в 00:00 местно време — това е
 * запълнител, не разписание. Преобразувано в софийско време то става 01:00 и
 * изглежда като истински час, което значи, че каналът обявява несъществуващо
 * начало на сесия.
 *
 * Пазим го като отделен флаг, а не като null в `scheduled_at_utc`, защото
 * датата е вярна и е полезна — само часът липсва.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('f2_race_sessions', function (Blueprint $table) {
            $table->boolean('time_tbc')->default(false)->after('ends_at_utc');
        });
    }

    public function down(): void
    {
        Schema::table('f2_race_sessions', function (Blueprint $table) {
            $table->dropColumn('time_tbc');
        });
    }
};
